<?php

namespace LPagery\service\live_render;

use LPagery\service\image_endpoint\VirtualImageMap;
use LPagery\service\substitution\SubstitutionHandler;

/**
 * List-context alt/title seam for Live Mode (ADR 0013, Phase 4).
 *
 * In archives, search, feeds and Views a live entry's featured image keeps the source attachment's
 * static URL — one shared, cacheable fetch instead of an N-per-page PHP-served Virtual Image URL — so
 * only the alt/title need to become per-entry. Singular renders are handled elsewhere (the output-
 * buffer pass, Phase 3) and must stay untouched by this seam.
 *
 * Pure by construction: ((image attributes, source attachment id, entry Row Data, Virtual Image Map)
 * → substituted attributes). The substitution runs only when the image being rendered is one of the
 * entry's virtual sources, so an unrelated image on the same page (a logo, a legacy per-page copy)
 * passes through unchanged and non-live posts pay nothing. The thin WP-filter shell
 * ({@see \LPagery\io\hooks\LiveRenderHooks}) and the View renderer supply the entry context.
 */
class LiveListImageAttributes
{
    private SubstitutionHandler $substitutionHandler;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    /**
     * Substitute the entry's Row Data into the image's `alt`/`title` attributes, when the image is one
     * of the entry's virtual sources. Any other attribute (src, srcset, sizes, class …) is returned
     * verbatim — list contexts keep the static source URL.
     *
     * @param array<string,mixed> $attr             the image attributes (as passed by
     *                                              `wp_get_attachment_image_attributes`, or assembled
     *                                              by the View renderer)
     * @param int                 $attachment_id    the source attachment being rendered
     * @param array<string,mixed> $row_data         the entry's Row Data (placeholder key => value)
     * @param array<string,mixed> $attachment_pairs the entry's persisted `attachment_id_pairs` value
     * @return array<string,mixed> the attributes with alt/title substituted where applicable
     */
    public function substitute(array $attr, int $attachment_id, array $row_data, array $attachment_pairs): array
    {
        if (empty($row_data) || !$this->is_virtual_source($attachment_id, $attachment_pairs)) {
            return $attr;
        }

        $params = LiveRowParams::build($row_data);
        foreach (array('alt', 'title') as $key) {
            if (!isset($attr[$key]) || !is_string($attr[$key]) || $attr[$key] === '') {
                continue;
            }
            $substituted = $this->substitutionHandler->lpagery_substitute($params, $attr[$key]);
            if (is_string($substituted)) {
                $attr[$key] = $substituted;
            }
        }
        return $attr;
    }

    /**
     * Is this attachment one of the entry's Virtual Image Map sources? Legacy `{source, target}` sets
     * (real per-page copies with alt already substituted at generation) carry no virtual map and are
     * therefore always a no-op here. Public so a caller assembling its own attributes (the View
     * renderer) can confirm the featured image is a virtual source before fetching its alt template.
     *
     * @param array<string,mixed> $attachment_pairs
     */
    public function is_virtual_source(int $attachment_id, array $attachment_pairs): bool
    {
        if ($attachment_id <= 0 || !VirtualImageMap::is_virtual($attachment_pairs)) {
            return false;
        }
        foreach (VirtualImageMap::entries($attachment_pairs) as $entry) {
            if ($entry['source_id'] === $attachment_id) {
                return true;
            }
        }
        return false;
    }
}
