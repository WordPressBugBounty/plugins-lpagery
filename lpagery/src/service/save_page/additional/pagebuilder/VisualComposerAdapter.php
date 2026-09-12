<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use LPagery\model\Params;
use LPagery\service\substitution\SubstitutionHandler;
use WP_Post;

class VisualComposerAdapter implements PagebuilderAdapter
{
    private $substitutionHandler;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    public function supports(WP_Post $template_post, Params $params): bool
    {
        $post_meta_keys = get_post_custom_keys($template_post->ID);
        return is_array($post_meta_keys) && in_array('vcv-pageContent', $post_meta_keys);
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        $meta_values = get_post_custom_values("vcv-pageContent", $template_post_id);
        // Clear the generated page's copy once, before the loop: a delete per iteration would wipe
        // the values an earlier iteration just added when the template carries several rows.
        delete_post_meta($generated_page_id, "vcv-pageContent");
        foreach ($meta_values as $meta_value) {
            if (is_string($meta_value)) {
                $meta_value = rawurldecode($meta_value);
                $meta_value = $this->substitutionHandler->lpagery_substitute($params, $meta_value);
                add_post_meta($generated_page_id, "vcv-pageContent", rawurlencode($meta_value));
            }
        }
    }
}
