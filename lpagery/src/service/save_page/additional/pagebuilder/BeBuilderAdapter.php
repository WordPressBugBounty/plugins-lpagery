<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use LPagery\model\Params;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\utils\Utils;
use Mfn_Helper;
use WP_Post;

class BeBuilderAdapter implements PagebuilderAdapter
{
    private $substitutionHandler;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    public function supports(WP_Post $template_post, Params $params): bool
    {
        $post_meta_keys = get_post_custom_keys($template_post->ID);
        return is_array($post_meta_keys) && in_array('mfn-page-items', $post_meta_keys);
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        // Handle preview meta
        $preview_meta_value = get_post_meta($template_post_id, "mfn-builder-preview", true);
        if (Utils::is_base_64_encoded($preview_meta_value)) {
            $preview_meta_value = base64_decode($preview_meta_value);
        }
        $preview_meta_value = maybe_unserialize($preview_meta_value);
        $preview_meta_value = $this->substitutionHandler->lpagery_substitute($params, $preview_meta_value);
        delete_post_meta($generated_page_id, "mfn-builder-preview");
        update_post_meta($generated_page_id, "mfn-builder-preview", $preview_meta_value);

        $items_meta_value = get_post_meta($template_post_id, "mfn-page-items", true);
        if (Utils::is_base_64_encoded($items_meta_value)) {
            $items_meta_value = base64_decode($items_meta_value);
        }
        $items_meta_value = maybe_unserialize($items_meta_value);
        $items_meta_value = $this->substitutionHandler->lpagery_substitute($params, $items_meta_value);
        delete_post_meta($generated_page_id, "mfn-page-items");
        update_post_meta($generated_page_id, "mfn-page-items", $items_meta_value);

        if (class_exists("Mfn_Helper")) {
            $object = get_post_meta($generated_page_id, 'mfn-page-object', true);
            $object = json_decode($object, true);
            Mfn_Helper::preparePostUpdate($object, $generated_page_id, 'mfn-page-local-style');
        }
    }
}
