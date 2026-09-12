<?php

namespace LPagery\service\live_render;

/**
 * `get_post_metadata` proxy for Live Mode stubs (ADR 0007/0010): on every NON-admin request —
 * pages, sitemaps, feeds, REST — a meta read on a stub returns the Template Page's value, so
 * builders and SEO plugins see the template's metas everywhere they look. Physical/own metas
 * (featured image, LPagery tracking metas, the Render Mode marker) stay the stub's own.
 *
 * The hot path is kept cheap: admin, empty-key and own-key reads short-circuit before any stub
 * lookup, and the lookup itself is O(1) per post per request via {@see LiveStubResolver}.
 */
class LiveMetaProxy
{
    /**
     * Metas that are physically the stub's own and must never be proxied from the template.
     */
    private const OWN_META_KEYS = array(
        '_thumbnail_id',
        '_lpagery_render_mode',
        '_lpagery_page_source',
        '_lpagery_process',
        '_lpagery_plan',
    );

    private LiveStubResolver $resolver;

    public function __construct(LiveStubResolver $resolver)
    {
        $this->resolver = $resolver;
    }


    /**
     * @param mixed  $value     the short-circuit value passed by the get_post_metadata filter
     * @param int    $object_id the post whose meta is being read
     * @param string $meta_key  the meta key being read
     * @param bool   $single    whether a single value or the full array is expected (reduced by core)
     * @return mixed  the template's values for a proxied stub read, otherwise $value unchanged
     */
    public function filter($value, $object_id, $meta_key, $single)
    {
        if (is_admin()) {
            return $value;
        }
        if ($meta_key === '' || in_array($meta_key, self::OWN_META_KEYS, true)) {
            return $value;
        }

        $stub = $this->resolver->resolve((int)$object_id);
        if ($stub === null) {
            return $value;
        }

        // Return the template's raw values as an array and let WordPress reduce it: get_metadata()
        // takes $check[0] when $single is true. Fetching with single=false keeps array-valued metas
        // intact (returning them directly under $single would make core slice into the value), and
        // falling through when the template lacks the key avoids a warning and reads the stub's own
        // (absent) value — matching Classic Mode, which copies template metas at generation.
        $values = get_post_meta($stub->template_id, $meta_key, false);
        if (empty($values)) {
            return $value;
        }
        return $values;
    }
}
