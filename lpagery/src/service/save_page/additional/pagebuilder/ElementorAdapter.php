<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use Elementor\Plugin as ElementorPlugin;
use LPagery\model\Params;
use WP_Post;

class ElementorAdapter implements PagebuilderAdapter
{
    public function supports(WP_Post $template_post, Params $params): bool
    {
        $post_meta_keys = get_post_custom_keys($template_post->ID);
        return is_array($post_meta_keys) && in_array('_elementor_version', $post_meta_keys);
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        delete_post_meta($generated_page_id, "_elementor_css");
        if (class_exists("Elementor\Plugin")) {
            // The documents manager returns null for a post type it does not handle, and a save
            // failure inside Elementor must not abort the page save: log it and keep going, as the
            // Divi and Brizy adapters do.
            try {
                $documents_manager = ElementorPlugin::instance()->documents;
                $document = $documents_manager->get($generated_page_id);
                if ($document) {
                    $document->save([]);
                }
            } catch (\Throwable $e) {
                error_log("Error saving elementor document for post " . $generated_page_id . " " . $e->getMessage());
            }
        }
    }
}
