<?php

namespace LPagery\service\save_page;

use DateTime;
use DateTimeInterface;
use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\model\PageCreationDashboardSettings;
use LPagery\model\Params;
use WP_Post;
class PostFieldProvider {
    private WP_Post $template_post;

    private ?WP_Post $target_post;

    private Params $params;

    private array $json_data;

    private PageCreationDashboardSettings $post_settings;

    private ?DynamicPageAttributeHandler $dynamicPageAttributeHandler;

    private SubstitutionHandler $substitutionHandler;

    public function __construct(
        WP_Post $template_post,
        Params $params,
        SubstitutionHandler $substitutionHandler,
        ?WP_Post $target_post,
        ?DynamicPageAttributeHandler $dynamicPageAttributeHandler
    ) {
        $this->template_post = $template_post;
        $this->params = $params;
        $this->target_post = $target_post;
        $this->dynamicPageAttributeHandler = $dynamicPageAttributeHandler;
        $this->substitutionHandler = $substitutionHandler;
        $this->json_data = $params->raw_data ?? array();
        $this->post_settings = $params->settings;
    }

    public function get_content() : string {
        $content = $this->template_post->post_content;
        return $this->substitutionHandler->lpagery_substitute( $this->params, $content );
    }

    public function get_content_filtered() {
        return $this->substitutionHandler->lpagery_substitute( $this->params, $this->template_post->post_content_filtered );
    }

    public function get_title() : string {
        return $this->substitutionHandler->lpagery_substitute( $this->params, $this->template_post->post_title );
    }

    public function get_excerpt() : string {
        return $this->substitutionHandler->lpagery_substitute( $this->params, $this->template_post->post_excerpt );
    }

    public function get_slug() : string {
        $slug = $this->template_post->post_title;
        $substituted = $this->substitutionHandler->lpagery_substitute_slug( $this->params, $slug );
        return sanitize_title( strip_tags( $substituted ) );
    }

    public function get_author( $process_id ) {
        $author_id = $this->template_post->post_author;
        return $author_id;
    }

    public function get_parent() : int {
        $parent_id = 0;
        return $parent_id;
    }

    public function get_status( $publish_datetime ) {
        if ( lpagery_fs()->is_free_plan() ) {
            return 'publish';
        }
        $status_to_set = $this->post_settings->status_from_process;
        $status_to_from_dashboard = $this->post_settings->status_from_dashboard;
        return $status_to_set;
    }

    public function get_publish_datetime() : ?string {
        $publish_datetime = null;
        if ( lpagery_fs()->is_free_plan() ) {
            return null;
        }
        return $publish_datetime;
    }

}
