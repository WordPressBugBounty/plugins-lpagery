<?php

namespace LPagery\service\live_render;

use LPagery\service\image_endpoint\VirtualImageMap;
use LPagery\service\image_endpoint\VirtualImageUrl;

/**
 * Derives a render-time image replacement map for a Live Mode page from its persisted source→target
 * attachment ID pairs (Phase 1) against the CURRENT attachment state (ADR 0010) — never frozen URLs,
 * so regenerated thumbnails change the derived URLs with no page write.
 *
 * Live Mode (ADR 0013) reuses the same pairs column with a Virtual Image Map shape instead: each
 * copy-form Image column resolves to a source attachment ID + substituted filename, and the source
 * attachment's full-size URL is rewritten to that page's Virtual Image URL
 * (`/lpagery-img/{stub-post-id}/{filename}`) — no per-page attachment exists. Both channels can
 * coexist on one page (a copy-form value goes virtual; a download/reference value keeps its real
 * duplicate), and a legacy `{source, target}`-only stub renders through the duplicated-attachment
 * channel exactly as before.
 *
 * The map is split into two replacement channels applied over the final HTML in {@see apply()}:
 *  - `urls`: full-size and every registered size/srcset variant URL, plus each URL's
 *    JSON-escaped-slash variant (`https:\/\/…`). For the virtual channel this also covers the
 *    opposite-scheme absolute form and the root-relative form of each source URL ({@see
 *    self::add_virtual_url_forms()}). Applied with one {@see strtr()} pass — substring-safe: the
 *    file-extension / `-WxH` boundary means no source URL is a positional prefix of a sized-variant URL,
 *    and while the root-relative key IS a substring of the absolute forms, strtr's longest-match-first
 *    left-to-right pass replaces the longer absolute key first and consumes it.
 *  - `ids`: `wp-image-{id}` classes. Applied with a single digit-boundary regex pass so a source id
 *    that is a numeric PREFIX of an unrelated class (e.g. `wp-image-123` when only id 12 is mapped)
 *    is left untouched.
 *
 * A bare builder-JSON `"id":{id}` rewrite was considered but deliberately NOT done: `"id":N` matches
 * any equal integer JSON id anywhere in the document (a post/menu/widget id, not just an attachment),
 * and scoping it to a builder image-object shape can't be done safely over flat HTML. The rendered
 * output is already covered by the URL and `wp-image-{id}` rewrites, so the risk isn't worth taking.
 *
 * Known v1 limitation: the whole substitution is document-global (a template image reused in site
 * chrome is remapped too) — inherent to the output-buffer approach (ADR 0007) and accepted for v1.
 *
 * Missing/deleted attachments are skipped gracefully. The derived map is memoised per pair-set for
 * the current request; attachment metadata reads are already meta-cached by WordPress.
 */
class LiveImageMap
{
    /** @var array<string, array{urls: array<string,string>, ids: array<string,string>}> in-request memo keyed by the pair-set hash */
    private array $cache = array();

    private VirtualImageAvailability $availability;

    public function __construct(VirtualImageAvailability $availability)
    {
        $this->availability = $availability;
    }

    /**
     * @param array<string,mixed> $attachment_pairs ['source' => int[], 'target' => int[]] (legacy
     *                                              duplicated-attachment shape) and/or the
     *                                              ['virtual_images' => ...] Virtual Image Map shape
     * @param int                 $stub_post_id     the Live Mode stub's post ID, needed to build its
     *                                              Virtual Image URLs; 0 skips the virtual channel
     * @return array{urls: array<string,string>, ids: array<string,string>}
     */
    public function derive(array $attachment_pairs, int $stub_post_id = 0): array
    {
        $key = md5(serialize(array($attachment_pairs, $stub_post_id)));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $urls = array();
        $ids = array();

        $this->add_duplicated_attachment_pairs($urls, $ids, $attachment_pairs);
        $this->add_virtual_image_pairs($urls, $attachment_pairs, $stub_post_id);

        $map = array('urls' => $urls, 'ids' => $ids);
        $this->cache[$key] = $map;
        return $map;
    }

    /**
     * Legacy duplicated-attachment channel: remap each source attachment's full-size + size-variant
     * URLs and `wp-image-{id}` class to the per-page target attachment's.
     *
     * @param array<string,string> $urls
     * @param array<string,string> $ids
     * @param array<string,mixed>  $attachment_pairs
     */
    private function add_duplicated_attachment_pairs(array &$urls, array &$ids, array $attachment_pairs): void
    {
        $source_ids = isset($attachment_pairs['source']) && is_array($attachment_pairs['source'])
            ? array_values($attachment_pairs['source']) : array();
        $target_ids = isset($attachment_pairs['target']) && is_array($attachment_pairs['target'])
            ? array_values($attachment_pairs['target']) : array();

        $count = min(count($source_ids), count($target_ids));
        for ($i = 0; $i < $count; $i++) {
            $source_id = (int)$source_ids[$i];
            $target_id = (int)$target_ids[$i];
            if ($source_id <= 0 || $target_id <= 0) {
                continue;
            }

            $source_url = wp_get_attachment_url($source_id);
            $target_url = wp_get_attachment_url($target_id);
            if (!is_string($source_url) || $source_url === '' || !is_string($target_url) || $target_url === '') {
                // Source or target attachment is gone — nothing to remap for this pair.
                continue;
            }

            $this->add_url_pair($urls, $source_url, $target_url);
            $this->add_size_variants($urls, $source_id, $target_id, $source_url, $target_url);

            $ids['wp-image-' . $source_id] = 'wp-image-' . $target_id;
        }
    }

    /**
     * Virtual Image Map channel (ADR 0013): rewrite each source attachment's full-size URL — plus every
     * registered size variant / srcset URL — to this page's Virtual Image URL. Size variants map to a
     * `-WxH`-suffixed virtual filename ({@see VirtualImageUrl::sized_filename()}) whose dimensions the
     * endpoint reverses to serve the source's matching size file. The full URL and every sized URL each
     * get their JSON-escaped-slash variant via {@see self::add_url_pair()}, so builder data, inline CSS
     * backgrounds, og:image and JSON-LD image URLs all remap in the single {@see self::apply()} pass.
     *
     * Unlike the legacy channel there is no per-page target attachment: `wp-image-{id}` classes keep
     * pointing at the (unchanged) source attachment id, so nothing is added to the `ids` channel here.
     *
     * When two copy-form Image columns share one source attachment, their entries share one source URL —
     * a flat string map can only carry one target per source string, so the last entry's virtual filename
     * wins document-wide. Inherent to the document-global substitution (see the class doc's v1 note);
     * every entry's Virtual Image URL still serves either way, only the filename embedded in the HTML
     * collapses to one.
     *
     * @param array<string,string> $urls
     * @param array<string,mixed>  $attachment_pairs
     */
    private function add_virtual_image_pairs(array &$urls, array $attachment_pairs, int $stub_post_id): void
    {
        if ($stub_post_id <= 0 || !VirtualImageMap::is_virtual($attachment_pairs)) {
            return;
        }
        // Source URL Fallback (CONTEXT.md): when Endpoint Health / environment can't serve the Image
        // Endpoint site-wide, add no virtual replacement pairs — the buffered source attachment URL
        // (alt/title already substituted by the text pass) is what serves. The Virtual Image Map stays
        // persisted and the endpoint stays registered, so recovery is render-time only.
        if (!$this->availability->site_wide_enabled()) {
            return;
        }
        foreach (VirtualImageMap::entries($attachment_pairs) as $entry) {
            // Per-image Source URL Fallback (CONTEXT.md): when this image's source file is missing on
            // disk (offloaded media with "remove local files", or a deleted file) the endpoint would
            // 404 its Virtual Image URL, so add no virtual pairs for it — including its size variants.
            // The buffered source attachment URL (alt/title already substituted) serves for this image
            // only, while every other image on the page keeps its Virtual Image URL.
            if (!$this->availability->source_file_exists($entry['source_id'])) {
                continue;
            }
            $source_url = wp_get_attachment_url($entry['source_id']);
            if (!is_string($source_url) || $source_url === '') {
                continue;
            }
            $this->add_virtual_url_forms($urls, $source_url, $stub_post_id, $entry['filename']);
            $this->add_virtual_size_variants($urls, $entry['source_id'], $entry['filename'], $source_url, $stub_post_id);
        }
    }

    /**
     * Map every registered size variant of the source attachment to a `-WxH`-suffixed Virtual Image URL,
     * derived from current metadata (`wp_get_attachment_metadata`) relative to the source full-size URL.
     * The suffix carries the size's actual pixel dimensions so the endpoint can reverse it to the
     * source's matching registered size file.
     *
     * @param array<string,string> $urls
     */
    private function add_virtual_size_variants(array &$urls, int $source_id, string $filename, string $source_url, int $stub_post_id): void
    {
        $source_meta = wp_get_attachment_metadata($source_id);
        if (!is_array($source_meta) || empty($source_meta['sizes']) || !is_array($source_meta['sizes'])) {
            return;
        }
        $source_base = $this->dirname_url($source_url);
        foreach ($source_meta['sizes'] as $source_size) {
            if (!is_array($source_size) || empty($source_size['file'])
                || !isset($source_size['width'], $source_size['height'])) {
                continue;
            }
            $sized_filename = VirtualImageUrl::sized_filename($filename, (int)$source_size['width'], (int)$source_size['height']);
            $this->add_virtual_url_forms($urls, $source_base . '/' . $source_size['file'], $stub_post_id, $sized_filename);
        }
    }

    /**
     * Register every source-URL form that builder content might store for one virtual image target, so
     * the single {@see self::apply()} strtr pass remaps them all. Three forms are covered, each with its
     * JSON-escaped-slash variant via {@see self::add_url_pair()}:
     *  - the absolute source URL as WordPress returns it → the absolute Virtual Image URL;
     *  - the opposite-scheme absolute URL (`http://` content on an `https://` site and vice versa) →
     *    the absolute Virtual Image URL;
     *  - the root-relative form (`/wp-content/uploads/…`) → the RELATIVE Virtual Image URL path.
     *
     * The root-relative source form is a substring of every absolute form, so under strtr's
     * longest-match-first, left-to-right pass it never clobbers this site's own absolute occurrences
     * (the longer absolute key wins at the URL's start and the match is consumed). Mapping it to the
     * relative virtual path — rather than the absolute one — also keeps an unrelated absolute URL on a
     * *different* host that coincidentally shares the same path from being rewritten into concatenated
     * garbage: it stays a syntactically valid URL on that host.
     *
     * @param array<string,string> $urls
     */
    private function add_virtual_url_forms(array &$urls, string $source_url, int $stub_post_id, string $virtual_filename): void
    {
        $virtual_absolute = VirtualImageUrl::full_url($stub_post_id, $virtual_filename);

        $this->add_url_pair($urls, $source_url, $virtual_absolute);

        $opposite_scheme = $this->opposite_scheme_url($source_url);
        if ($opposite_scheme !== '' && $opposite_scheme !== $source_url) {
            $this->add_url_pair($urls, $opposite_scheme, $virtual_absolute);
        }

        $relative = $this->relative_url($source_url);
        if ($relative !== '' && $relative !== $source_url) {
            $this->add_url_pair($urls, $relative, VirtualImageUrl::path($stub_post_id, $virtual_filename));
        }
    }

    /**
     * The same absolute URL with its `http`/`https` scheme flipped, or '' when the URL carries no
     * `http(s)://` scheme (e.g. a protocol-relative or already-relative URL, which has no scheme to flip).
     */
    private function opposite_scheme_url(string $url): string
    {
        if (strncmp($url, 'https://', 8) === 0) {
            return 'http://' . substr($url, 8);
        }
        if (strncmp($url, 'http://', 7) === 0) {
            return 'https://' . substr($url, 7);
        }
        return '';
    }

    /**
     * The root-relative form (scheme + host stripped, leading `/…` path kept) of an absolute
     * `http(s)://host/path` URL, or '' when the URL has no such host-prefixed path.
     */
    private function relative_url(string $url): string
    {
        if (preg_match('#^https?://[^/]+(/.*)$#', $url, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * Apply the derived map to the final HTML: URL substitutions in one strtr pass, then the
     * digit-boundary-safe numeric-id token pass.
     *
     * @param array<string,mixed> $attachment_pairs
     * @param int                 $stub_post_id     the Live Mode stub's post ID (see {@see self::derive()})
     */
    public function apply(string $html, array $attachment_pairs, int $stub_post_id = 0): string
    {
        if ($html === '') {
            return $html;
        }
        $map = $this->derive($attachment_pairs, $stub_post_id);
        if (!empty($map['urls'])) {
            $html = strtr($html, $map['urls']);
        }
        if (!empty($map['ids'])) {
            $html = $this->replace_id_tokens($html, $map['ids']);
        }
        return $html;
    }

    /**
     * Replace `wp-image-{id}` tokens in a single left-to-right pass with a trailing digit boundary,
     * so a source id that is a numeric prefix of an unrelated class (`wp-image-123` when only id 12
     * is mapped) is never corrupted. Longest tokens are tried first so a shorter id can't shadow a
     * longer one.
     *
     * @param array<string,string> $ids search => replace, keys are literal `wp-image-N`
     */
    private function replace_id_tokens(string $html, array $ids): string
    {
        $tokens = array_keys($ids);
        usort($tokens, static function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        $alternatives = array();
        foreach ($tokens as $token) {
            $alternatives[] = preg_quote($token, '/');
        }

        $regex = '/(' . implode('|', $alternatives) . ')(?![0-9])/';
        $result = preg_replace_callback($regex, static function ($matches) use ($ids) {
            return $ids[$matches[1]] ?? $matches[0];
        }, $html);

        return is_string($result) ? $result : $html;
    }

    /**
     * Map every registered size variant of the source attachment to the target's same-named size,
     * built from current metadata (`wp_get_attachment_metadata`) relative to each full-size URL.
     *
     * @param array<string,string> $urls
     */
    private function add_size_variants(array &$urls, int $source_id, int $target_id, string $source_url, string $target_url): void
    {
        $source_meta = wp_get_attachment_metadata($source_id);
        $target_meta = wp_get_attachment_metadata($target_id);
        if (!is_array($source_meta) || !is_array($target_meta)) {
            return;
        }
        if (empty($source_meta['sizes']) || !is_array($source_meta['sizes'])
            || empty($target_meta['sizes']) || !is_array($target_meta['sizes'])) {
            return;
        }

        $source_base = $this->dirname_url($source_url);
        $target_base = $this->dirname_url($target_url);

        foreach ($source_meta['sizes'] as $size_name => $source_size) {
            if (!isset($target_meta['sizes'][$size_name])) {
                continue;
            }
            $target_size = $target_meta['sizes'][$size_name];
            if (empty($source_size['file']) || empty($target_size['file'])) {
                continue;
            }
            $this->add_url_pair(
                $urls,
                $source_base . '/' . $source_size['file'],
                $target_base . '/' . $target_size['file']
            );
        }
    }

    /**
     * Register a URL replacement plus its JSON-escaped-slash variant (builder data stores
     * `https:\/\/…`), so both plain HTML attributes and JSON-in-script get remapped.
     *
     * @param array<string,string> $urls
     */
    private function add_url_pair(array &$urls, string $source, string $target): void
    {
        $urls[$source] = $target;

        $source_escaped = str_replace('/', '\/', $source);
        $target_escaped = str_replace('/', '\/', $target);
        if ($source_escaped !== $source) {
            $urls[$source_escaped] = $target_escaped;
        }
    }

    /**
     * Directory portion of a URL without mangling the scheme's `//` (unlike PHP `dirname`).
     */
    private function dirname_url(string $url): string
    {
        $pos = strrpos($url, '/');
        return $pos === false ? $url : substr($url, 0, $pos);
    }
}
