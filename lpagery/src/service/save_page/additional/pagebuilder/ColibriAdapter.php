<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use LPagery\model\Params;
use WP_Post;

class ColibriAdapter implements PagebuilderAdapter
{
    public function supports(WP_Post $template_post, Params $params): bool
    {
        $post_meta_keys = get_post_custom_keys($template_post->ID);
        return is_array($post_meta_keys) && in_array('extend_builder', $post_meta_keys);
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        // Schedule CSS regeneration - Colibri generates CSS client-side via JavaScript
        // This sets a flag that triggers CSS regeneration on next page load
        // Matching the pattern from Regenerate::schedule() in regenerate.php

        delete_option('colibri_page_builder_regenerate_tries_count');
        if (class_exists('\ExtendBuilder\Regenerate')) {
            \ExtendBuilder\Regenerate::schedule();
        }
    }
}
