<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use LPagery\model\Params;
use LPagery\service\substitution\SubstitutionHandler;
use WP_Post;
use function Breakdance\Data\set_meta as breakdance_set_meta;
use function Breakdance\Render\generateCacheForPost as breakdance_generate_cache_for_post;

class BreakdanceAdapter implements PagebuilderAdapter
{
    private $substitutionHandler;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    public function supports(WP_Post $template_post, Params $params): bool
    {
        $post_meta_keys = get_post_custom_keys($template_post->ID);
        return is_array($post_meta_keys) && in_array('_breakdance_data', $post_meta_keys);
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        $meta_value = get_post_meta($template_post_id, "_breakdance_data", true);
        if (is_string($meta_value) && function_exists('Breakdance\Data\set_meta')) {
            $decoded = json_decode($meta_value, true);
            if (isset($decoded["tree_json_string"])) {
                delete_post_meta($generated_page_id, "_breakdance_data");
                delete_post_meta($generated_page_id, "_breakdance_css_file_paths_cache");
                delete_post_meta($generated_page_id, "_breakdance_dependency_cache");

                $decoded_tree = (json_decode($decoded["tree_json_string"], true));
                $params->numeric_keys[] = $template_post_id;
                $params->numeric_values[] = $generated_page_id;
                $result = $this->substitutionHandler->lpagery_substitute($params, $decoded_tree);


                $tree = json_encode($result);
                breakdance_set_meta($generated_page_id, '_breakdance_data', ['tree_json_string' => $tree,]);

                wp_update_post(['ID' => $generated_page_id]);

                breakdance_generate_cache_for_post($generated_page_id);

                do_action("breakdance_after_save_document", $generated_page_id);
            }

        }
    }
}
