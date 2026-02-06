<?php

namespace LPagery\service\save_page\additional;

use Brizy_Editor_Post;
use Elementor\Plugin as ElementorPlugin;
use ET_Core_PageResource;
use LPagery\model\Params;
use LPagery\service\Beautify_Html;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\utils\Utils;
use Mfn_Helper;
use function Breakdance\Data\set_meta as breakdance_set_meta;
use function Breakdance\Render\generateCacheForPost as breakdance_generate_cache_for_post;

class PagebuilderHandler
{
    private static $instance;

    private $substitutionHandler;
    private Divi5Handler $divi5Handler;

    private function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
        $this->divi5Handler = Divi5Handler::get_instance($substitutionHandler);
    }


    public static function get_instance(SubstitutionHandler $substitutionHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($substitutionHandler);
        }
        return self::$instance;
    }


    public function lpagery_handle_pagebuilder($sourcePostId, $targetPostId, Params $params)
    {
        // Check for Divi 5 content first (before any other processing)
        // Divi 5 uses Gutenberg blocks in post_content, not post_meta
        // We check the SOURCE post since the target content may already have backslashes stripped
        $source_post = get_post($sourcePostId);
        if ($source_post && $this->divi5Handler->is_divi5_content($source_post->post_content)) {
            $this->divi5Handler->handle($sourcePostId, $targetPostId, $params);
        }

        if (in_array("{lpagery_content}", $params->keys)) {
            self::handle_gutenberg($targetPostId);
        }

        $post_meta_keys = get_post_custom_keys($sourcePostId);
        if ($post_meta_keys == null) {
            return;
        }
        if (in_array('_elementor_version', $post_meta_keys)) {
            self::lpagery_handle_elementor($targetPostId);
        }
        if (in_array('_breakdance_data', $post_meta_keys)) {
            self::lpagery_handle_visual_breakdance($sourcePostId, $targetPostId, $params);
        }
        if (in_array('brizy', $post_meta_keys)) {
            self::lpagery_handle_brizy($sourcePostId, $targetPostId, $params);
        }
        if (in_array('vcv-pageContent', $post_meta_keys)) {
            self::lpagery_handle_visual_composer($sourcePostId, $targetPostId, $params);
        }
        if (in_array('mfn-page-items', $post_meta_keys)) {
            self::lpagery_handle_bebuilder($sourcePostId, $targetPostId, $params);
        }

        if (in_array('_et_builder_version', $post_meta_keys)) {
            self::lpagery_handle_divi($targetPostId);
        }
        if (in_array('_seedprod_page', $post_meta_keys) || in_array('_seedprod_page_uuid', $post_meta_keys)) {
            self::lpagery_handle_seedprod($sourcePostId, $targetPostId, $params);
        }

        if (in_array('_bricks_page_settings', $post_meta_keys) || in_array('_bricks_page_content_2', $post_meta_keys)) {
            self::lpagery_handle_bricks($sourcePostId, $targetPostId, $params);
        }

        if (in_array('extend_builder', $post_meta_keys)) {
            self::lpagery_handle_colibri();
        }

    }

    private function lpagery_handle_seedprod($sourcePostId, $targetPostId, Params $params)
    {
        global $wpdb;
        $raw_post_content_filtered = ($wpdb->get_var("SELECT post_content_filtered FROM $wpdb->posts WHERE ID = $sourcePostId"));
        $post_content_filtered = $this->substitutionHandler->lpagery_substitute($params, ($raw_post_content_filtered));
        wp_update_post(array('ID' => $targetPostId,
            'post_content_filtered' => wp_slash($post_content_filtered)));
    }

    /**
     * @param $target_post_id
     *
     * @return void
     */
    private function lpagery_handle_elementor($target_post_id)
    {
        delete_post_meta($target_post_id, "_elementor_css");
        if (class_exists("Elementor\Plugin")) {
            $documents_manager = ElementorPlugin::instance()->documents;
            $document = $documents_manager->get($target_post_id);
            $document->save([]);
        }
    }

    private function lpagery_handle_brizy($source_post_id, $target_post_id, Params $params)
    {
        $meta_values = get_post_custom_values("brizy", $source_post_id);
        foreach ($meta_values as $meta_value) {
            $deserialized = maybe_unserialize($meta_value);

            $deserialized = self::replace_brizy_data($deserialized, 'compiled_html', $params);
            $deserialized = self::replace_brizy_data($deserialized, 'editor_data', $params);

            delete_post_meta($target_post_id, "brizy");
            add_post_meta($target_post_id, "brizy", Utils::lpagery_recursively_slash_strings($deserialized));
        }
        if (class_exists("Brizy_Editor_Post")) {
            try {
                $brizy_Editor_Post = new Brizy_Editor_Post($target_post_id);
                $brizy_Editor_Post->savePost();
            } catch (\Throwable $e) {
                error_log("Error saving brizy post " . $target_post_id . " " . $e->getMessage());
            }
        }
    }

    private function replace_brizy_data($deserialized, $key, Params $params)
    {
        $plain_html = base64_decode($deserialized['brizy-post'][$key]);
        $substituted_html = $this->substitutionHandler->lpagery_substitute($params, $plain_html);
        $html_base64 = base64_encode($substituted_html);
        $deserialized['brizy-post'][$key] = $html_base64;

        return $deserialized;
    }

    private function lpagery_handle_visual_composer($sourcePostId, $targetPostId, Params $params)
    {
        $meta_values = get_post_custom_values("vcv-pageContent", $sourcePostId);
        foreach ($meta_values as $meta_value) {
            if (is_string($meta_value)) {
                $meta_value = rawurldecode($meta_value);
                $meta_value = $this->substitutionHandler->lpagery_substitute($params, $meta_value);
                delete_post_meta($targetPostId, "vcv-pageContent");
                add_post_meta($targetPostId, "vcv-pageContent", rawurlencode($meta_value));
            }
        }
    }

    private function lpagery_handle_visual_breakdance($sourcePostId, $targetPostId, Params $params)
    {
        $meta_value = get_post_meta($sourcePostId, "_breakdance_data", true);
        if (is_string($meta_value) && function_exists('Breakdance\Data\set_meta')) {
            $decoded = json_decode($meta_value, true);
            if (isset($decoded["tree_json_string"])) {
                delete_post_meta($targetPostId, "_breakdance_data");
                delete_post_meta($targetPostId, "_breakdance_css_file_paths_cache");
                delete_post_meta($targetPostId, "_breakdance_dependency_cache");

                $decoded_tree = (json_decode($decoded["tree_json_string"], true));
                $params->numeric_keys[] = $sourcePostId;
                $params->numeric_values[] = $targetPostId;
                $result = $this->substitutionHandler->lpagery_substitute($params, $decoded_tree);


                $tree = json_encode($result);
                breakdance_set_meta($targetPostId, '_breakdance_data', ['tree_json_string' => $tree,]);

                wp_update_post(['ID' => $targetPostId]);

                breakdance_generate_cache_for_post($targetPostId);

                do_action("breakdance_after_save_document", $targetPostId);
            }

        }


    }

    private function lpagery_handle_bebuilder($sourcePostId, $targetPostId, $params)
    {
        // Handle preview meta
        $preview_meta_value = get_post_meta($sourcePostId, "mfn-builder-preview", true);
        if (Utils::is_base_64_encoded($preview_meta_value)) {
            $preview_meta_value = base64_decode($preview_meta_value);
        }
        $preview_meta_value = maybe_unserialize($preview_meta_value);
        $preview_meta_value = $this->substitutionHandler->lpagery_substitute($params, $preview_meta_value);
        delete_post_meta($targetPostId, "mfn-builder-preview");
        update_post_meta($targetPostId, "mfn-builder-preview", $preview_meta_value);

        $items_meta_value = get_post_meta($sourcePostId, "mfn-page-items", true);
        if (Utils::is_base_64_encoded($items_meta_value)) {
            $items_meta_value = base64_decode($items_meta_value);
        }
        $items_meta_value = maybe_unserialize($items_meta_value);
        $items_meta_value = $this->substitutionHandler->lpagery_substitute($params, $items_meta_value);
        delete_post_meta($targetPostId, "mfn-page-items");
        update_post_meta($targetPostId, "mfn-page-items", $items_meta_value);

        if (class_exists("Mfn_Helper")) {
            $object = get_post_meta($targetPostId, 'mfn-page-object', true);
            $object = json_decode($object, true);
            Mfn_Helper::preparePostUpdate($object, $targetPostId, 'mfn-page-local-style');
        }
    }


    private function handle_gutenberg($targetPostId)
    {
        $post = get_post($targetPostId);
        $post_content = $post->post_content;

        if (!has_blocks($post_content) || str_contains($post_content, 'wp:kadence')) {
            return;
        }
        $formatted = self::lpagery_do_blocks($post_content);

        // Update the post content using wp_update_post
        wp_update_post(array('ID' => $targetPostId,
            'post_content' => $formatted));
    }

    private function lpagery_do_blocks($content)
    {
        $blocks = parse_blocks($content);
        $serialized = self::serialize_blocks($blocks);

        return $serialized;
    }

    public function serialize_blocks($blocks)
    {
        return implode("\r\n", array_map(self::class . '::serialize_block', $blocks));
    }

    public function serialize_block($block)
    {
        $block_content = '';

        $index = 0;
        foreach ($block['innerContent'] as $chunk) {
            $block_content .= is_string($chunk) ? $chunk : serialize_block($block['innerBlocks'][$index++]);
        }

        if (!is_array($block['attrs'])) {
            $block['attrs'] = array();
        }


        $beautify = new Beautify_Html(array('indent_inner_html' => false,
            'indent_char' => " ",
            'indent_size' => 2,
            'wrap_line_length' => 9999999999,
            'unformatted' => [],
            'preserve_newlines' => false,
            'max_preserve_newlines' => 9999999999,
            'indent_scripts' => 'normal'
            // keep|separate|normal
        ));
        $block_content = $beautify->beautify($block_content, $block['blockName']);


        return self::get_comment_delimited_block_content($block['blockName'], $block['attrs'], $block_content);
    }

    private function get_comment_delimited_block_content($block_name, $block_attributes, $block_content)
    {
        if (is_null($block_name)) {
            return $block_content;
        }

        $serialized_block_name = strip_core_block_namespace($block_name);
        $serialized_attributes = empty($block_attributes) ? '' : serialize_block_attributes($block_attributes) . ' ';

        if (empty($block_content)) {
            return sprintf("\r\n<!-- wp:%s\r\n %s/-->\r\n", $serialized_block_name, $serialized_attributes);
        }

        return sprintf("\r\n<!-- wp:%s %s-->\r\n%s\r\n<!-- /wp:%s -->\r\n", $serialized_block_name,
            $serialized_attributes, $block_content, $serialized_block_name);
    }

    private function lpagery_handle_divi($targetPostId)
    {
        if (class_exists('ET_Core_PageResource')) {
            try {
                ET_Core_PageResource::remove_static_resources((string)$targetPostId, 'all');
            } catch (\Throwable $throwable) {
                lpagery_info_log("Error removing static divi resources for post " . $targetPostId . " " . $throwable->getMessage());
                error_log("Error removing  divi resources for post " . $targetPostId . " " . $throwable->getMessage());
            }
        }

    }

    private function lpagery_handle_bricks($source_post_id, $target_post_id, Params $params)
    {
        $settings_value = get_post_meta($source_post_id, '_bricks_page_settings', true);
        if ($settings_value) {
            $bricks_settings = maybe_unserialize($settings_value);
            $replaced_settings = $this->substitutionHandler->lpagery_substitute($params, $bricks_settings);
            delete_post_meta($target_post_id, "_bricks_page_settings");
            add_post_meta($target_post_id, "_bricks_page_settings", $replaced_settings);
        }


        $content_value = get_post_meta($source_post_id, '_bricks_page_content_2', true);
        if ($content_value) {
            $bricks_content = maybe_unserialize($content_value);
            $replaced_content = $this->substitutionHandler->lpagery_substitute($params, $bricks_content);
            delete_post_meta($target_post_id, "_bricks_page_content_2");
            add_post_meta($target_post_id, "_bricks_page_content_2", $replaced_content);
        }


    }

    private function lpagery_handle_colibri()
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
