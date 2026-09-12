<?php

namespace LPagery\service\save_page;

use LPagery\model\PageCreationDashboardSettings;
use LPagery\model\Params;
use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\substitution\SubstitutionHandler;
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
        if ( $this->dynamicPageAttributeHandler ) {
            // No tier guard: lpagery_get_content carries its own and answers the template content
            // unchanged below Extended. An outer `__premium_only` guard would be removed whole from
            // the free build and take the only reader of these two fields with it.
            $content = $this->dynamicPageAttributeHandler->lpagery_get_content( $this->json_data, $content );
        }
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
        // A page that already exists keeps the URL it was published under when the tier cannot read
        // the set's slug pattern: renaming it after the template title would move a live URL with no
        // redirect. Free Materialize (ADR 0019) is the path that reaches this. Written out of
        // non-suffixed calls so it survives the Freemius strip, with `is_premium()` because a free
        // build's site can still carry a paid plan.
        if ( $this->target_post && !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial( 'standard' )) ) {
            return (string) $this->target_post->post_name;
        }
        $slug = $this->template_post->post_title;
        $substituted = $this->substitutionHandler->lpagery_substitute_slug( $this->params, $slug );
        $sanitized = sanitize_title( strip_tags( $substituted ) );
        // A Generated Page published before slug patterns transliterated diacritics keeps the
        // URL it was published under: rewriting `caf%c3%a9-berlin` to `cafe-berlin` on an
        // update run would move a live URL without a redirect. Pages created from now on and
        // any page whose slug changed for another reason take the current spelling.
        if ( $this->target_post && $this->target_post->post_name !== $sanitized ) {
            $legacy = $this->substitutionHandler->lpagery_substitute_legacy_slug( $this->params, $slug );
            $legacy = sanitize_title( strip_tags( $legacy ) );
            if ( $legacy === $this->target_post->post_name ) {
                return $legacy;
            }
        }
        return $sanitized;
    }

    public function get_author( $process_id ) {
        // Same rule as the slug: below the tier that owns the author rules, an existing page keeps
        // its own author instead of being handed the template's.
        if ( $this->target_post && !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial( 'extended' )) ) {
            return $this->target_post->post_author;
        }
        $author_id = $this->template_post->post_author;
        return $author_id;
    }

    public function get_parent() : int {
        $parent_id = 0;
        if ( !is_post_type_hierarchical( $this->template_post->post_type ) ) {
            return 0;
        }
        // An existing page keeps its place in the page tree when the tier cannot read the set's
        // parent setting, rather than being moved to the top level.
        if ( $this->target_post && !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial( 'standard' )) ) {
            return (int) $this->target_post->post_parent;
        }
        return $parent_id;
    }

    public function get_parent_search_term() : ?string {
        $search_term = null;
        if ( !is_post_type_hierarchical( $this->template_post->post_type ) ) {
            return null;
        }
        return $search_term;
    }

    public function get_status( $publish_datetime ) {
        if ( lpagery_fs()->is_free_plan() ) {
            // An existing page keeps its status: a materialized page that was a draft stays a draft,
            // and one that was published stays published.
            return ( $this->target_post ? $this->target_post->post_status : 'publish' );
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
