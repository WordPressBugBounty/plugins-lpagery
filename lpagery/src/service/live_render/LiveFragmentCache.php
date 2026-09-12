<?php

namespace LPagery\service\live_render;

/**
 * The render-fragment cache (ADR 0008): the builder's pre-substitution content output for a
 * Template Page, cached once and reused for every live page of the Page Set. Substitution still
 * runs per request over the served fragment, so the shared blob never carries one page's values —
 * it is the template rendered with raw Placeholders, identical across the whole set.
 *
 * Storage is a WordPress transient (`lpagery_live_fragment_{template_id}`) rather than a custom
 * table: it is the simplest store that survives object-cache drop-ins (transients route through the
 * object cache when one is installed, else the options table) and needs no schema. The blob is
 * LONGTEXT-scale rendered HTML, so it must never autoload — WordPress stores non-expiring transients
 * with autoload='yes', so we pass a TTL to force autoload='no'. The TTL is only a safety net: the
 * real invalidation is the stored Template modified time no longer matching plus the explicit delete
 * on template save.
 */
class LiveFragmentCache
{
    private const TRANSIENT_PREFIX = 'lpagery_live_fragment_';
    /** One week. Bounds staleness only if a save hook is ever missed; not the primary invalidation. */
    private const TTL_SECONDS = 7 * 24 * 60 * 60;
    /**
     * Stored-payload format version: bumped when the fragment's canonical form changes, so blobs
     * written by an older plugin version read as a miss and are re-captured. v2: builder document
     * ids are canonicalised to the Template id instead of carrying the capturing stub's id. v3: every
     * shortcode is held out of the HTML as a Deferred Shortcode and stored beside it (ADR 0020), so a
     * v2 blob — which baked the capturing page's shortcode output in — must never be served.
     */
    private const FORMAT_VERSION = 3;

    /**
     * The cached fragment for a template — HTML plus its Deferred Shortcodes — or null when there is
     * no valid one. A stored fragment whose Template modified time no longer matches the current one
     * is stale: it is dropped and treated as a miss so the caller re-renders and re-stores.
     */
    public function get(int $template_id, string $expected_modified_gmt): ?LiveFragmentPayload
    {
        $stored = get_transient($this->key($template_id));
        if (!is_array($stored) || !isset($stored['html'], $stored['modified_gmt'])) {
            return null;
        }
        if ((int)($stored['v'] ?? 0) !== self::FORMAT_VERSION) {
            return null;
        }
        if ((string)$stored['modified_gmt'] !== $expected_modified_gmt) {
            $this->delete($template_id);
            return null;
        }
        return new LiveFragmentPayload((string)$stored['html'], $this->read_shortcodes($stored));
    }

    /**
     * Store the pre-substitution builder content for a template, the Deferred Shortcodes held out of
     * it, and the Template modified time it was rendered from, so a later save invalidates it even if
     * the delete hook is missed.
     *
     * @param array<string,string> $shortcodes marker => original shortcode text (ADR 0020)
     */
    public function store(int $template_id, string $html, array $shortcodes, string $modified_gmt): void
    {
        set_transient(
            $this->key($template_id),
            array(
                'v' => self::FORMAT_VERSION,
                'html' => $html,
                'shortcodes' => $shortcodes,
                'modified_gmt' => $modified_gmt,
            ),
            self::TTL_SECONDS
        );
    }

    /**
     * The held shortcode texts of a stored blob, normalised to marker => text. A template with no
     * (non-exempt) shortcode stores none, and anything that is not a string pair is dropped rather
     * than fed to `do_shortcode()` later.
     *
     * @param array<string,mixed> $stored
     * @return array<string,string>
     */
    private function read_shortcodes(array $stored): array
    {
        if (!isset($stored['shortcodes']) || !is_array($stored['shortcodes'])) {
            return array();
        }
        $shortcodes = array();
        foreach ($stored['shortcodes'] as $marker => $text) {
            if (is_string($marker) && is_string($text)) {
                $shortcodes[$marker] = $text;
            }
        }
        return $shortcodes;
    }

    public function delete(int $template_id): void
    {
        delete_transient($this->key($template_id));
    }

    private function key(int $template_id): string
    {
        return self::TRANSIENT_PREFIX . $template_id;
    }
}
