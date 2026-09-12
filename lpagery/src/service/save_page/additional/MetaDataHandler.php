<?php

namespace LPagery\service\save_page\additional;

use LPagery\service\substitution\SubstitutionHandler;
use LPagery\model\Params;
use LPagery\utils\Utils;
use WP_Post;

class MetaDataHandler
{

    private SubstitutionHandler $substitutionHandler;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    /**
     * Classic Mode copy path: mirror the Template Page's custom meta onto the Generated Page, then
     * stamp the LPagery tracking metas.
     *
     * The tracking stamp runs unconditionally. The generic copy still short-circuits on a Template
     * Page with no custom meta, but that early return must not take the stamp with it: a Generated
     * Page without `_lpagery_plan` / `_lpagery_process` / `_lpagery_page_source` silently breaks
     * everything keyed on them, the `[lpagery_link]` plan gate included.
     */
    public function lpagery_copy_post_meta_info($new_id, WP_Post $template, $meta_excludelist,Params $params)
    {
        $this->copy_template_meta($new_id, $template, $meta_excludelist, $params);
        $this->reset_tracking_metas($new_id, $template, $params);
    }

    /**
     * The generic per-key copy of the Template Page's custom meta, substituting each value with the
     * row's data. No-ops when the Template Page has no custom meta keys.
     *
     * @param array<int, string>|mixed $meta_excludelist
     */
    private function copy_template_meta($new_id, WP_Post $template, $meta_excludelist, Params $params): void
    {
        $post_meta_keys = \get_post_custom_keys($template->ID);
        if (empty($post_meta_keys)) {
            return;
        }
        if (!is_array($meta_excludelist)) {
            $meta_excludelist = [];
        }
        $meta_excludelist = \array_merge($meta_excludelist, Utils::lpagery_get_default_filtered_meta_names());


        $meta_excludelist_string = '(' . \implode(')|(', $meta_excludelist) . ')';
        if (strpos($meta_excludelist_string, '*') !== false) {
            $meta_excludelist_string = \str_replace(['*'], ['[a-zA-Z0-9_]*'], $meta_excludelist_string);

            $meta_keys = [];
            foreach ($post_meta_keys as $meta_key) {
                if (!\preg_match('#^' . $meta_excludelist_string . '$#', $meta_key)) {
                    $meta_keys[] = $meta_key;
                }
            }
        } else {
            $meta_keys = \array_diff($post_meta_keys, $meta_excludelist);
        }

        foreach ($meta_keys as $meta_key) {

            $meta_values = get_post_custom_values($meta_key, $template->ID);

            delete_post_meta($new_id, $meta_key);

            foreach ($meta_values as $meta_value) {
                $meta_value = maybe_unserialize($meta_value);

                $replacedValue = $this->substitutionHandler->lpagery_substitute($params, $meta_value);

                add_post_meta($new_id, $meta_key, Utils::lpagery_recursively_slash_strings($replacedValue));
            }
        }
    }

    /**
     * Live Mode stub metas (ADR 0010): the LPagery tracking metas plus the substituted
     * featured image, which is a physical scalar on the stub. Everything else stays on the
     * Template Page and is proxied at render time, so the generic meta copy is skipped.
     */
    public function lpagery_copy_stub_meta_info($new_id, WP_Post $template, Params $params)
    {
        $this->reset_tracking_metas($new_id, $template, $params);

        $thumbnail_id = get_post_meta($template->ID, "_thumbnail_id", true);
        delete_post_meta($new_id, "_thumbnail_id");
        if ($thumbnail_id !== "" && $thumbnail_id !== false && $thumbnail_id !== null) {
            $substituted_thumbnail_id = $this->substitutionHandler->lpagery_substitute($params, $thumbnail_id);
            add_post_meta($new_id, "_thumbnail_id", $substituted_thumbnail_id);
        }
    }

    private function reset_tracking_metas($new_id, WP_Post $template, Params $params): void
    {
        delete_post_meta($new_id, "_lpagery_page_source");
        delete_post_meta($new_id, "_lpagery_process");
        delete_post_meta($new_id, "_lpagery_plan");

        add_post_meta($new_id, "_lpagery_page_source", $template->ID);
        add_post_meta($new_id, "_lpagery_process", $params->process_id);
        add_post_meta($new_id, "_lpagery_plan", lpagery_fs()->is_free_plan() ? 'FREE' : 'PRO');
    }

}
