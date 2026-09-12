<?php

namespace LPagery\service\live_render;

use LPagery\model\Params;

/**
 * Builds the {@see Params} a Live Mode render needs from a Generated Page's Row Data: brace-wrapped
 * placeholder keys mapped to their values, plus the raw data and the page's spintax setting. Shared
 * by the whole-HTML render pass ({@see LiveRenderPass}) and the list-context alt/title seam
 * ({@see LiveListImageAttributes}) so both substitute Row Data through the exact same param shape.
 *
 * Image processing is always off here: live renders never re-copy attachments (ADR 0013), so the
 * substitution engine only rewrites text placeholders.
 */
class LiveRowParams
{
    /**
     * @param array<string,mixed> $row_data   the Generated Page's Row Data (key => value)
     * @param int|null            $spin_seed  the page's persisted Spin Seed, so a live render resolves
     *                                        the same spintax picks Classic would (ADR 0017)
     */
    public static function build(array $row_data, bool $spintax_enabled = false, ?int $spin_seed = null): Params
    {
        $keys = array();
        $values = array();
        foreach ($row_data as $key => $value) {
            $key = (string)$key;
            if ($key === '' || $key === 'lpagery_id') {
                continue;
            }
            $prefix = !str_starts_with($key, "{") ? "{" : "";
            $suffix = !str_ends_with($key, "}") ? "}" : "";
            $keys[] = $prefix . $key . $suffix;
            $values[] = is_null($value) ? "" : $value;
        }

        $params = new Params();
        $params->keys = $keys;
        $params->values = $values;
        $params->raw_data = $row_data;
        $params->spintax_enabled = $spintax_enabled;
        $params->spin_seed = $spin_seed;
        $params->image_processing_enabled = false;
        return $params;
    }
}
