<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use LPagery\model\Params;
use LPagery\service\substitution\SubstitutionHandler;
use WP_Post;

class BricksAdapter implements PagebuilderAdapter
{
    private $substitutionHandler;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    public function supports(WP_Post $template_post, Params $params): bool
    {
        $post_meta_keys = get_post_custom_keys($template_post->ID);
        return is_array($post_meta_keys)
            && (in_array('_bricks_page_settings', $post_meta_keys) || in_array('_bricks_page_content_2', $post_meta_keys));
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        $settings_value = get_post_meta($template_post_id, '_bricks_page_settings', true);
        if ($settings_value) {
            $bricks_settings = maybe_unserialize($settings_value);
            $replaced_settings = $this->substitutionHandler->lpagery_substitute($params, $bricks_settings);
            delete_post_meta($generated_page_id, "_bricks_page_settings");
            add_post_meta($generated_page_id, "_bricks_page_settings", $replaced_settings);
        }


        $content_value = get_post_meta($template_post_id, '_bricks_page_content_2', true);
        if ($content_value) {
            $bricks_content = maybe_unserialize($content_value);
            $replaced_content = $this->substitutionHandler->lpagery_substitute($params, $bricks_content);
            delete_post_meta($generated_page_id, "_bricks_page_content_2");
            add_post_meta($generated_page_id, "_bricks_page_content_2", $replaced_content);
        }
    }
}
