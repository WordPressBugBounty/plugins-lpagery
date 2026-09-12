<?php

namespace LPagery\service\live_render;

/**
 * The Deferred Shortcode seam (ADR 0020): every shortcode is held out of the Render Fragment and
 * executed per request for the page being viewed.
 *
 * The fragment (ADR 0008) is the builder's pre-substitution output of a Template Page, captured once
 * and reused for every Stub Post of the Page Set — but the builder has already *executed* every
 * shortcode by then, so anything page-dependent ([lpagery_view], [lpagery_link], breadcrumbs, an
 * Elementor dynamic element's placeholder) would be baked in with the capturing page's output. This
 * seam splits that in two:
 *
 * - *Capture* runs on WordPress's `pre_do_shortcode_tag` choke point, which fires for every shortcode
 *   with its full original text before the callback runs — including the ones builders expand
 *   themselves. While a capture window is open, each shortcode is swapped for an opaque marker and
 *   its text is held; the fragment therefore stores markers, never output.
 * - *Expansion* runs per request over the served HTML: each held text first receives the current
 *   page's Row Data through the ordinary live pass, then executes, then replaces its marker. Running
 *   substitution before execution is what makes a Placeholder inside a shortcode attribute work in
 *   Live Mode as it does in Classic Mode.
 *
 * Markers are bracket-free and made only of `[A-Za-z0-9_]`, so they survive `wptexturize`, `wpautop`
 * and HTML attribute contexts — a raw shortcode left in an attribute would be entity-encoded by
 * WordPress's own shortcode pass and never expand again. The random per-capture token keeps a marker
 * from ever colliding with content.
 *
 * Pure by construction: no WordPress state beyond the filters it is handed. The request wiring (when
 * the window opens and closes, and where expansion runs) lives in {@see LiveRenderController}.
 */
class LiveShortcodeDeferral
{
    /** Marker prefix; the rest is a per-capture random token plus the shortcode's index. */
    private const MARKER_PREFIX = 'lpagery_deferred_';

    /** Whether a capture window is open. Outside a window every shortcode runs the ordinary way. */
    private bool $windowOpen = false;
    /** Random per-capture token, so a marker can never collide with content or an older fragment. */
    private string $token = '';
    /** @var array<string,string> marker => original shortcode text, in insertion order. */
    private array $held = array();

    /**
     * Open a capture window: from here on {@see self::capture_shortcode()} holds every non-exempt
     * shortcode. Previously held shortcodes are dropped — one window captures one fragment.
     */
    public function open_window(): void
    {
        $this->windowOpen = true;
        $this->token = bin2hex(random_bytes(8));
        $this->held = array();
    }

    public function close_window(): void
    {
        $this->windowOpen = false;
    }

    public function is_window_open(): bool
    {
        return $this->windowOpen;
    }

    /** The shortcodes held during the current window, marker => original text, in insertion order.
     *
     * @return array<string,string>
     */
    public function get_held_shortcodes(): array
    {
        return $this->held;
    }

    /** Close the window and drop everything held, so the next capture starts clean. */
    public function reset(): void
    {
        $this->windowOpen = false;
        $this->token = '';
        $this->held = array();
    }

    /**
     * `pre_do_shortcode_tag` filter: while a capture window is open, hold this shortcode's text and
     * return its marker so the fragment carries the marker instead of the shortcode's output. Outside
     * a window, or for a tag a site exempts through `lpagery_live_defer_shortcode`, the filter's own
     * value is returned untouched and WordPress runs the shortcode as usual.
     *
     * Nesting needs no handling: this fires for the outer shortcode before its callback parses the
     * inner ones, so the whole outer text is held and per-request execution runs the inner ones.
     *
     * @param mixed                       $return the short-circuit value passed by core (false = run)
     * @param string                      $tag    the shortcode tag
     * @param array<string|int,string>|string $attr the parsed attributes (a string for an empty atts list)
     * @param array<int,string>           $m      the regex match; `$m[0]` is the full original text
     * @return mixed the marker while deferring, else `$return` unchanged
     */
    public function capture_shortcode($return, $tag, $attr, $m)
    {
        if (!$this->windowOpen || !is_array($m) || !isset($m[0]) || !is_string($m[0])) {
            return $return;
        }
        if (!apply_filters('lpagery_live_defer_shortcode', true, (string)$tag, $attr)) {
            return $return;
        }
        $marker = self::MARKER_PREFIX . $this->token . '_' . count($this->held);
        $this->held[$marker] = $m[0];
        return $marker;
    }

    /**
     * Expand the Deferred Shortcodes of one request: substitute each held text with the current
     * page's values, execute it, and put the result where its marker sits in the HTML. Runs on the
     * capture request and on every fragment hit through this one path, so both render the same way.
     *
     * Execution happens with deferral suspended, so the inner shortcodes of a held nest run normally
     * and a window that is still open cannot re-defer what is being expanded. Replacement is a single
     * pass ({@see strtr()}), so a shortcode's own output can never be mistaken for another marker.
     *
     * @param string                  $html       the fragment (or captured content) carrying markers
     * @param array<string,string>    $shortcodes marker => original shortcode text
     * @param callable(string):string $substitute the live substitution pass for the page being served
     */
    public function expand(string $html, array $shortcodes, callable $substitute): string
    {
        if ($shortcodes === array()) {
            return $html;
        }
        $was_open = $this->windowOpen;
        $this->windowOpen = false;
        try {
            $replacements = array();
            foreach ($shortcodes as $marker => $text) {
                $replacements[(string)$marker] = (string)do_shortcode($substitute((string)$text));
            }
        } finally {
            $this->windowOpen = $was_open;
        }
        return strtr($html, $replacements);
    }
}
