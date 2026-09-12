<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use LPagery\model\Params;
use LPagery\service\substitution\SubstitutionHandler;
use WP_Post;

class SeedprodAdapter implements PagebuilderAdapter
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
            && (in_array('_seedprod_page', $post_meta_keys) || in_array('_seedprod_page_uuid', $post_meta_keys));
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        global $wpdb;
        $raw_post_content_filtered = ($wpdb->get_var("SELECT post_content_filtered FROM $wpdb->posts WHERE ID = $template_post_id"));
        $post_content_filtered = $this->substitutionHandler->lpagery_substitute($params, ($raw_post_content_filtered));
        wp_update_post(array('ID' => $generated_page_id,
            'post_content_filtered' => wp_slash($post_content_filtered)));
    }
}
