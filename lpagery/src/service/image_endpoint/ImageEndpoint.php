<?php

namespace LPagery\service\image_endpoint;

use LPagery\service\live_render\LiveStubResolver;

/**
 * The Image Endpoint HTTP shell (ADR 0013): the plugin's first rewrite rule. Registers
 * `/lpagery-img/{stub-post-id}/{filename}`, intercepts matching requests as early as possible
 * ({@see self::maybe_serve()} on `parse_request`, before the main post query) and streams the source
 * attachment's bytes on an exact Virtual Image Map match — everything else is a genuine 404 (the URL
 * space is closed). Free code, registered unconditionally, so a lapsed license never breaks serving.
 *
 * The decision is delegated to the pure {@see VirtualImageResolver}; the HTTP caching contract
 * (Cache-Control/Last-Modified/ETag + 304 revalidation) to the pure {@see ConditionalRequest}. This
 * class only does WordPress glue and byte streaming (E2E-covered).
 *
 * The rewrite flush lifecycle mirrors the database migrator's option-stamp: an activation flush plus a
 * version-stamped re-flush on upgrade, so the rule works without the user manually saving permalinks.
 */
class ImageEndpoint
{
    private const VERSION_OPTION = 'lpagery_rewrite_rules_version';
    /** Bump when the rewrite rule shape changes to force a re-flush on the next request after upgrade. */
    private const RULES_VERSION = '1';

    /**
     * A fixed `Last-Modified`/`ETag` epoch for the Edge Cache Probe image (2024-01-01 UTC): the probe
     * bytes are static and identical for every site, so a fixed validator makes an Edge Cache's
     * revalidation deterministic across probe fetches.
     */
    private const PROBE_LAST_MODIFIED = 1704067200;
    private const PROBE_MIME = 'image/png';
    /** A minimal 1x1 transparent PNG shipped inline — the smallest thing an Edge Cache will treat as an image. */
    private const PROBE_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private LiveStubResolver $resolver;
    private VirtualImageResolver $virtualResolver;
    private ?EdgeCacheProbeStore $probeStore;

    public function __construct(LiveStubResolver $resolver, VirtualImageResolver $virtualResolver, ?EdgeCacheProbeStore $probeStore = null)
    {
        $this->resolver = $resolver;
        $this->virtualResolver = $virtualResolver;
        $this->probeStore = $probeStore;
    }

    public function register_rewrite_rule(): void
    {
        add_rewrite_rule(VirtualImageUrl::rewrite_regex(), VirtualImageUrl::rewrite_target(), 'top');
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public function register_query_vars(array $vars): array
    {
        $vars[] = VirtualImageUrl::QUERY_VAR_POST;
        $vars[] = VirtualImageUrl::QUERY_VAR_FILE;
        return $vars;
    }

    /**
     * Version-stamped re-flush on upgrade (runs on `init` after the rule is registered): one autoloaded
     * option read per request, a flush only when the stamp changes.
     */
    public function maybe_flush_rewrite_rules(): void
    {
        if (get_option(self::VERSION_OPTION) !== self::RULES_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::VERSION_OPTION, self::RULES_VERSION);
        }
    }

    /**
     * Activation flush: register the rule then flush so it works immediately without the user saving
     * permalinks. Also stamps the version so the upgrade re-flush no-ops until the rule shape changes.
     */
    public function flush_on_activation(): void
    {
        $this->register_rewrite_rule();
        flush_rewrite_rules(false);
        update_option(self::VERSION_OPTION, self::RULES_VERSION);
    }

    /**
     * `parse_request` handler: serve or 404 a Virtual Image request, or fall through untouched for any
     * other request. Interception happens here (before the main query) so uncached image hits never run
     * a post query. Exits on a match or a genuine 404 within the closed URL space.
     *
     * @param object $wp the WP object being routed (has ->query_vars)
     */
    public function maybe_serve($wp): void
    {
        $query_vars = (is_object($wp) && isset($wp->query_vars) && is_array($wp->query_vars)) ? $wp->query_vars : array();
        $post_id = isset($query_vars[VirtualImageUrl::QUERY_VAR_POST]) ? (int)$query_vars[VirtualImageUrl::QUERY_VAR_POST] : 0;
        $filename = isset($query_vars[VirtualImageUrl::QUERY_VAR_FILE]) ? (string)$query_vars[VirtualImageUrl::QUERY_VAR_FILE] : '';
        if ($post_id < 0 || $filename === '') {
            // Not a Virtual Image request — leave the request for WordPress to route normally. (The reserved
            // probe stub id 0 is handled below, so only a negative id or an empty filename falls through here.)
            return;
        }

        // Closed URL space (ADR 0013): only honor the actual rewrite match. The query vars are registered,
        // so WordPress would populate them from a bare `?lpagery_img_post=…&lpagery_img_file=…` query
        // string too; that is not part of the documented contract, so fall through and let WP route it.
        $matched_rule = (is_object($wp) && isset($wp->matched_rule)) ? (string)$wp->matched_rule : '';
        if ($matched_rule !== VirtualImageUrl::rewrite_regex()) {
            return;
        }

        // Edge Cache Probe (issue #233): the reserved stub id 0 is never a real post, so a request under it
        // is a probe. Checked before the live-stubs/resolver gates so the probe can run on a fresh install
        // with zero generated pages — a matching token serves the static probe image, anything else 404s.
        if ($post_id === VirtualImageUrl::PROBE_STUB_ID) {
            $this->serve_probe($filename);
            return;
        }

        // Site-wide gate first: with zero live stubs the per-stub lookup can never match, so 404 after
        // only the cheap existence query — no per-stub resolve when Live Mode is unused.
        if (!$this->resolver->live_stubs_exist()) {
            $this->send_not_found();
            return;
        }

        // The stub must exist and be a live page (LiveStubResolver returns null otherwise); the filename
        // must exactly match one of its Virtual Image Map entries and its source file must still exist.
        $stub = $this->resolver->resolve($post_id);
        if ($stub !== null && !$this->stub_is_accessible($post_id)) {
            $this->send_not_found();
            return;
        }
        $attachment_pairs = ($stub !== null) ? $stub->attachment_pairs : null;
        $decision = $this->virtualResolver->resolve(
            $attachment_pairs,
            $filename,
            static function (int $source_id): bool {
                $file = get_attached_file($source_id);
                return is_string($file) && $file !== '' && file_exists($file);
            },
            static function (int $source_id): array {
                // Lazily surface the source attachment's registered sizes for a `-WxH` request, so the
                // resolver can map the suffix to the matching size-variant file (unregistered sizes 404).
                $meta = wp_get_attachment_metadata($source_id);
                if (!is_array($meta) || empty($meta['sizes']) || !is_array($meta['sizes'])) {
                    return array();
                }
                $sizes = array();
                foreach ($meta['sizes'] as $size) {
                    if (!is_array($size) || empty($size['file']) || !isset($size['width'], $size['height'])) {
                        continue;
                    }
                    $sizes[] = array(
                        'width' => (int)$size['width'],
                        'height' => (int)$size['height'],
                        'file' => (string)$size['file'],
                    );
                }
                return $sizes;
            }
        );

        if (!$decision->is_served()) {
            $this->send_not_found();
            return;
        }
        $this->stream($decision);
    }

    /**
     * Visibility gate for a resolved live stub: the live marker is post-status-agnostic (a conversion
     * can flip `_lpagery_render_mode` on a draft/private/future/trashed page without touching
     * `post_status`), so a stub's Virtual Image URLs must 404 for visitors until the page itself is
     * publicly viewable. Users who can edit the stub still get its images, so a draft preview renders
     * intact.
     */
    protected function stub_is_accessible(int $post_id): bool
    {
        return is_post_publicly_viewable($post_id) || current_user_can('edit_post', $post_id);
    }

    protected function send_not_found(): void
    {
        // Drop any buffered output so the 404 carries no stray body from a theme/plugin `init` echo.
        $this->clear_output_buffers();
        status_header(404);
        // Closed URL space (ADR 0014): a 404 here is cacheable for five minutes, so stale Virtual Image
        // references and bot fuzzing are absorbed by the CDN instead of booting WordPress each time. The
        // cookie strip is what makes that cache actually happen.
        $this->strip_set_cookie();
        $this->opt_into_server_caches(ConditionalRequest::NOT_FOUND_MAX_AGE);
        $this->emit_headers(ConditionalRequest::not_found_headers());
        $this->halt();
    }

    /**
     * Stream the source attachment's bytes under the substituted filename, with the full HTTP caching
     * contract (ADR 0013): `Cache-Control: public, max-age=86400` + `Last-Modified`/`ETag` revalidation,
     * and a `304 Not Modified` short-circuit for conditional requests. Never `immutable`, never a redirect
     * — crawlers must attribute the image to the Virtual Image URL, not the source file, and the URL must
     * survive a source-image swap (new file → new validators at the same URL within the cache window).
     *
     * A sized decision ({@see VirtualImageDecision::size_file()}) streams the source attachment's
     * registered size-variant file (same upload directory as the full-size file) instead of the original.
     */
    protected function stream(VirtualImageDecision $decision): void
    {
        $source_attachment_id = $decision->source_attachment_id();
        $file = get_attached_file($source_attachment_id);
        if (!is_string($file) || $file === '') {
            $this->send_not_found();
            return;
        }
        if ($decision->size_file() !== '') {
            // The size-variant file lives beside the full-size file, keyed only by basename in metadata.
            $dir = dirname($file);
            $file = ($dir === '' || $dir === '.') ? $decision->size_file() : $dir . '/' . $decision->size_file();
        }
        if (!file_exists($file)) {
            $this->send_not_found();
            return;
        }
        $mime = get_post_mime_type($source_attachment_id);
        if (!is_string($mime) || $mime === '') {
            $mime = 'application/octet-stream';
        }
        $size = filesize($file);
        $mtime = filemtime($file);
        if ($size === false || $mtime === false) {
            // A stat failure would coerce to 0 and stream real bytes under a bogus Content-Length/ETag —
            // 404 instead of serving a corrupt, wrongly-validated response.
            $this->send_not_found();
            return;
        }
        $etag = ConditionalRequest::etag($size, $mtime);

        if (ConditionalRequest::is_not_modified($etag, $mtime, $this->request_header('HTTP_IF_NONE_MATCH'), $this->request_header('HTTP_IF_MODIFIED_SINCE'))) {
            // 304 must carry no body — drop any buffered output before emitting the validators.
            $this->clear_output_buffers();
            status_header(304);
            $this->strip_set_cookie();
            $this->opt_into_server_caches(ConditionalRequest::max_age());
            $this->emit_headers(ConditionalRequest::not_modified_headers($etag, $mtime));
            $this->halt();
            return;
        }

        // Discard any buffered output (a theme/plugin `init` echo, a warning) so the image bytes are the
        // whole body — otherwise Content-Length is wrong and the image is corrupted.
        $this->clear_output_buffers();
        status_header(200);
        $this->strip_set_cookie();
        $this->opt_into_server_caches(ConditionalRequest::max_age());
        $this->emit_headers(ConditionalRequest::response_headers($mime, $size, $etag, $mtime));
        // A HEAD request wants the headers only — same 200 + validators, no body.
        if (strtoupper((string)$this->request_header('REQUEST_METHOD')) !== 'HEAD') {
            readfile($file);
        }
        $this->halt();
    }

    /**
     * Edge Cache Probe gate (issue #233): serve the static probe image only for a request whose token
     * matches the currently-stored run token (constant-time compare — this is an unauthenticated URL),
     * otherwise a genuine closed-space 404. Runs before any live-stubs/resolver gate so the probe works
     * on a fresh install; with no store or no in-flight run every probe URL is a 404.
     */
    protected function serve_probe(string $filename): void
    {
        $token = VirtualImageUrl::parse_probe_token($filename);
        if ($token === null || $this->probeStore === null) {
            $this->send_not_found();
            return;
        }
        $stored = $this->probeStore->get_token();
        if ($stored === null || $stored === '' || !hash_equals($stored, $token)) {
            $this->send_not_found();
            return;
        }
        // Origin-reach evidence for the probe's classification: count every token-matched request that
        // reached PHP (200 and 304 alike) — a fetch the endpoint never saw was absorbed by a cache upstream.
        $this->probeStore->record_hit();
        $this->stream_probe();
    }

    /**
     * Stream the inline probe image under the identical HTTP caching contract as a real Virtual Image URL
     * ({@see ConditionalRequest}): 200 + image content type + public Cache-Control/stale-while-revalidate,
     * strong ETag/Last-Modified, `Set-Cookie` stripped — so an Edge Cache's treatment of the probe
     * transfers to real images. Honors conditional requests with a 304 the same way {@see self::stream()}
     * does; the validators come from a fixed epoch since the bytes never change.
     */
    protected function stream_probe(): void
    {
        $bytes = (string)base64_decode(self::PROBE_IMAGE_BASE64, true);
        $size = strlen($bytes);
        $mtime = self::PROBE_LAST_MODIFIED;
        $etag = ConditionalRequest::etag($size, $mtime);

        if (ConditionalRequest::is_not_modified($etag, $mtime, $this->request_header('HTTP_IF_NONE_MATCH'), $this->request_header('HTTP_IF_MODIFIED_SINCE'))) {
            $this->clear_output_buffers();
            status_header(304);
            $this->strip_set_cookie();
            $this->opt_into_server_caches(ConditionalRequest::max_age());
            $this->emit_headers(ConditionalRequest::not_modified_headers($etag, $mtime));
            $this->halt();
            return;
        }

        $this->clear_output_buffers();
        status_header(200);
        $this->strip_set_cookie();
        $this->opt_into_server_caches(ConditionalRequest::max_age());
        $this->emit_headers(ConditionalRequest::response_headers(self::PROBE_MIME, $size, $etag, $mtime));
        // A HEAD request wants the headers only — same 200 + validators, no body.
        if (strtoupper((string)$this->request_header('REQUEST_METHOD')) !== 'HEAD') {
            echo $bytes;
        }
        $this->halt();
    }

    /**
     * @param array<string,string> $headers
     */
    protected function emit_headers(array $headers): void
    {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    protected function clear_output_buffers(): void
    {
        // Guard on the return value: a non-removable buffer would otherwise spin forever.
        while (ob_get_level() > 0 && ob_end_clean()) {
            // no-op: the condition does the work.
        }
    }

    /**
     * Strip any `Set-Cookie` header set earlier in boot (session, consent, analytics) before emitting a
     * cacheable image response. The bytes are public and identical for every visitor, but a single cookie
     * makes CDNs refuse to cache the response — so the cache hardening only pays off once the cookie is
     * gone. A no-op once headers are already on the wire.
     */
    protected function strip_set_cookie(): void
    {
        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }
    }

    /**
     * Opt this response into the **server-level caches that ignore standard `Cache-Control`** (issue #233)
     * — the layers that can absorb repeat image requests synchronously on the same box:
     *
     * - **LiteSpeed** honors `X-LiteSpeed-Cache-Control` for its server cache, and the LiteSpeed Cache
     *   plugin stamps every PHP route it does not recognize `no-cache` early in boot, which would veto
     *   caching of every Virtual Image response on LiteSpeed hosts (a huge share of shared hosting). So:
     *   drop any earlier stamp, emit our own header for plugin-less LiteSpeed servers, and use the
     *   plugin's public control hooks (no-ops when it is absent) so a plugin-managed decision agrees.
     * - **nginx FastCGI cache** honors `X-Accel-Expires` (seconds) as its authoritative TTL override —
     *   it works even on hosts whose config ignores upstream `Cache-Control`/`Expires`.
     *
     * Deliberately NOT handled: WordPress page-cache plugins (WP Rocket, W3 Total Cache, WP Super Cache,
     * WP Fastest Cache, Cache Enabler, …) cache **HTML documents only** — their buffer handlers skip
     * non-HTML output and stamp no veto headers on this route, so they neither absorb nor block these
     * responses and need no opt-in. Varnish and CDNs honor the standard `Cache-Control` already emitted.
     * Stacks without these caches ignore all of it. The TTL mirrors the `max-age` of the `Cache-Control`
     * actually emitted for the response ({@see ConditionalRequest::max_age()}), so a site that tunes the
     * TTL through the {@see ConditionalRequest::CACHE_CONTROL_FILTER} escape hatch is honored here too —
     * nginx treats `X-Accel-Expires` as an override, so a divergent default would outlive the tuned value.
     */
    protected function opt_into_server_caches(int $ttl): void
    {
        if (!headers_sent()) {
            header_remove('X-LiteSpeed-Cache-Control');
            header('X-LiteSpeed-Cache-Control: public, max-age=' . $ttl);
            header('X-Accel-Expires: ' . $ttl);
        }
        do_action('litespeed_control_force_cacheable', 'lpagery virtual image response');
        do_action('litespeed_control_set_ttl', $ttl);
    }

    /**
     * The response is complete — end the request. Isolated so the emission seams stay unit-testable without
     * the terminal `exit` tearing down the test process.
     */
    protected function halt(): void
    {
        exit;
    }

    protected function request_header(string $server_key): ?string
    {
        return isset($_SERVER[$server_key]) ? (string)$_SERVER[$server_key] : null;
    }
}
