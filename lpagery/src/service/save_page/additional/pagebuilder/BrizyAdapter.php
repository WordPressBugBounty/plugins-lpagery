<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use Brizy_Editor_Post;
use LPagery\model\Params;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\utils\Utils;
use WP_Post;

class BrizyAdapter implements PagebuilderAdapter
{
    private $substitutionHandler;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    public function supports(WP_Post $template_post, Params $params): bool
    {
        $post_meta_keys = get_post_custom_keys($template_post->ID);
        return is_array($post_meta_keys) && in_array('brizy', $post_meta_keys);
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        $meta_values = get_post_custom_values("brizy", $template_post_id);
        // Clear the generated page's copy once, before the loop: a delete per iteration would wipe
        // the values an earlier iteration just added when the template carries several rows.
        delete_post_meta($generated_page_id, "brizy");
        foreach ($meta_values as $meta_value) {
            $deserialized = maybe_unserialize($meta_value);

            $deserialized = self::replace_brizy_data($deserialized, 'compiled_html', $params);
            $deserialized = self::replace_brizy_data($deserialized, 'editor_data', $params);

            add_post_meta($generated_page_id, "brizy", Utils::lpagery_recursively_slash_strings($deserialized));
        }
        if (class_exists("Brizy_Editor_Post")) {
            try {
                $brizy_Editor_Post = new Brizy_Editor_Post($generated_page_id);
                $brizy_Editor_Post->savePost();
            } catch (\Throwable $e) {
                error_log("Error saving brizy post " . $generated_page_id . " " . $e->getMessage());
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
}
