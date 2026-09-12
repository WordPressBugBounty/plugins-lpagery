<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use ET_Core_PageResource;
use LPagery\model\Params;
use WP_Post;

class DiviAdapter implements PagebuilderAdapter
{
    public function supports(WP_Post $template_post, Params $params): bool
    {
        $post_meta_keys = get_post_custom_keys($template_post->ID);
        return is_array($post_meta_keys) && in_array('_et_builder_version', $post_meta_keys);
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        if (class_exists('ET_Core_PageResource')) {
            try {
                ET_Core_PageResource::remove_static_resources((string)$generated_page_id, 'all');
            } catch (\Throwable $throwable) {
                lpagery_info_log("Error removing static divi resources for post " . $generated_page_id . " " . $throwable->getMessage());
                error_log("Error removing  divi resources for post " . $generated_page_id . " " . $throwable->getMessage());
            }
        }
    }
}
