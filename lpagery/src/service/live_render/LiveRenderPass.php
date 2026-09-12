<?php

namespace LPagery\service\live_render;

use LPagery\service\substitution\SubstitutionHandler;
use Throwable;

/**
 * Pure render seam for Live Mode: substitute a Generated Page's Row Data (placeholders +
 * spintax) into already-rendered Template Page HTML. Composes the existing
 * {@see SubstitutionHandler} so every surface — title tag, head, JSON-LD, body — is covered by
 * one pass regardless of which builder or SEO plugin produced it (ADR 0007).
 *
 * Image-pair remapping (Phase 3) runs as a second pass via {@see LiveImageMap}: the page's
 * source→target attachment pairs are resolved to a render-time replacement map against current
 * attachment state and applied over the whole HTML — img src/srcset, `wp-image-{id}` classes,
 * builder JSON ids and image URLs inside inline CSS.
 */
class LiveRenderPass
{
    private SubstitutionHandler $substitutionHandler;
    private LiveImageMap $imageMap;

    public function __construct(SubstitutionHandler $substitutionHandler, LiveImageMap $imageMap)
    {
        $this->substitutionHandler = $substitutionHandler;
        $this->imageMap = $imageMap;
    }

    /**
     * @param array<string,mixed> $row_data        the Generated Page's Row Data (key => value)
     * @param array<string,mixed> $image_pairs     source→target attachment ID pairs, applied as the
     *                                             render-time image remap after placeholder substitution
     * @param bool                $spintax_enabled the page's generation-time spintax setting — spintax
     *                                             only runs when the user enabled it (Classic parity)
     * @param int|null            $stub_post_id    the Live Mode stub's post ID, needed by the Virtual
     *                                             Image Map channel to build this page's
     *                                             `/lpagery-img/{stub-post-id}/…` URLs
     * @param int|null            $spin_seed       the page's persisted Spin Seed. Classic resolves
     *                                             spintax ONCE at generation; a live render re-runs it
     *                                             per request, so resolving every block from the stored
     *                                             seed (ADR 0017) keeps each render of the page picking
     *                                             the same variants — the same picks Classic would make,
     *                                             so a Classic↔Live conversion keeps the wording too.
     */
    public function substitute(string $html, array $row_data, array $image_pairs = array(), bool $spintax_enabled = false, ?int $stub_post_id = null, ?int $spin_seed = null): string
    {
        if ($html === '') {
            return $html;
        }
        try {
            if (!empty($row_data)) {
                $params = LiveRowParams::build($row_data, $spintax_enabled, $spin_seed);
                $substituted = $this->substitutionHandler->lpagery_substitute($params, $html);
                if (is_string($substituted)) {
                    $html = $substituted;
                }
            }
            // The Virtual Image Map channel (ADR 0013) needs the stub post ID to build this page's
            // `/lpagery-img/{stub-post-id}/…` URLs.
            return $this->imageMap->apply($html, $image_pairs, $stub_post_id ?? 0);
        } catch (Throwable $e) {
            error_log("LPagery live render substitution failed: " . $e->getMessage());
            return $html;
        }
    }
}
