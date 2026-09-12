<?php

namespace LPagery\service\live_render;

/**
 * One stored Render Fragment (ADR 0008) as the render pipeline consumes it: the Template Page's
 * pre-substitution builder HTML plus the Deferred Shortcodes held out of it (ADR 0020).
 *
 * The two always travel together — HTML without its held texts would serve raw markers, held texts
 * without their HTML have nowhere to go — which is why the cache hands back this pair instead of a
 * string. Both halves are shared across the whole Page Set; the per-page work (substitution and
 * shortcode execution) happens on the way out, per request.
 */
class LiveFragmentPayload
{
    /** The fragment HTML, Placeholders unsubstituted and every deferred shortcode replaced by its marker. */
    public string $html;
    /**
     * The Deferred Shortcodes held out of this fragment, marker => original shortcode text, in the
     * order they were captured. Empty when the template has no (non-exempt) shortcodes.
     *
     * @var array<string,string>
     */
    public array $shortcodes;

    /** @param array<string,string> $shortcodes marker => original shortcode text */
    public function __construct(string $html, array $shortcodes = array())
    {
        $this->html = $html;
        $this->shortcodes = $shortcodes;
    }
}
