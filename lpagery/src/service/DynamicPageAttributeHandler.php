<?php

namespace LPagery\service;

use DateTime;
use LPagery\service\settings\SettingsController;
use LPagery\data\repository\GeneratedPageRepository;
use LPagery\model\BaseParams;
use Throwable;
class DynamicPageAttributeHandler {
    private SettingsController $settingsController;

    private GeneratedPageRepository $generatedPageRepository;

    private FindPostService $findPostService;

    public function __construct( SettingsController $settingsController, GeneratedPageRepository $generatedPageRepository, FindPostService $findPostService ) {
        $this->settingsController = $settingsController;
        $this->generatedPageRepository = $generatedPageRepository;
        $this->findPostService = $findPostService;
    }

    /**
     * The gate is written out of non-suffixed calls, not as the `__premium_only` `if` the sibling
     * getters use: Freemius removes such an `if` whole from the free build, which would leave the
     * injected settings controller with no reader at all. `is_premium()` is part of the condition on purpose. Freemius removes a whole `if` whose
     * condition is a `__premium_only` call, so the gate has to be written out of non-suffixed
     * calls to survive — and `is_plan_or_trial()` alone is true on a free build whose site already
     * carries a paid plan (a licence activated before the premium zip is installed). The compound
     * form is exactly what `is_plan_or_trial__premium_only()` evaluates, and it survives the strip.
     */
    public function lpagery_get_author( $process_id, $json_data ) {
        if ( !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial( 'extended' )) ) {
            return 0;
        }
        if ( !array_key_exists( "lpagery_author", $json_data ) ) {
            return get_user_by( "id", $this->settingsController->getAuthorId( $process_id ) );
        }
        $lpagery_author = $json_data["lpagery_author"];
        $found_author = get_user_by( "id", $lpagery_author );
        if ( !$found_author ) {
            $found_author = get_user_by( "email", $lpagery_author );
        }
        if ( !$found_author ) {
            $found_author = get_user_by( "login", $lpagery_author );
        }
        return $found_author;
    }

    public function lpagery_get_status( $json_data, $status_from_dashboard ) {
        return "publish";
    }

    public function lpagery_get_parent( BaseParams $params, $post_type, ?int $parent_id_from_dashboard ) : ?array {
        if ( !is_post_type_hierarchical( $post_type ) ) {
            return null;
        }
        $json_data = $params->raw_data ?? array();
        $parent_id_from_dashboard = $parent_id_from_dashboard ?? 0;
        if ( !array_key_exists( "lpagery_parent", $json_data ) ) {
            return $this->generatedPageRepository->find_post_by_id( $parent_id_from_dashboard );
        }
        $lpagery_parent_term = $json_data["lpagery_parent"];
        return $this->findPostService->lpagery_find_post_or_default(
            $params,
            $lpagery_parent_term,
            $parent_id_from_dashboard,
            $post_type
        );
    }

    public function lpagery_get_template( $params, $post_type, $template_id_from_dashboard ) {
        $json_data = $params->raw_data ?? array();
        if ( !array_key_exists( "lpagery_template", $json_data ) ) {
            return $this->generatedPageRepository->find_post_by_id( $template_id_from_dashboard );
        }
        $lpagery_template_term = $json_data["lpagery_template"];
        return $this->findPostService->lpagery_find_post_or_default(
            $params,
            $lpagery_template_term,
            $template_id_from_dashboard,
            $post_type
        );
    }

    /**
     * Strip-safe tier gate, for the same reason as {@see self::lpagery_get_author()}: the date parser
     * below is private and would lose its only call site in the stripped free build.
     */
    public function lpagery_get_publish_date( $json_data, $publish_date_from_dashboard ) : ?string {
        if ( !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial( 'extended' )) ) {
            return $publish_date_from_dashboard;
        }
        // Check if "lpagery_publish_date" key exists in the JSON data
        if ( !array_key_exists( "lpagery_publish_date", $json_data ) || !$json_data["lpagery_publish_date"] ) {
            return $publish_date_from_dashboard;
        }
        $lpagery_publish_date = $json_data["lpagery_publish_date"];
        $dateTime = self::getISODate( $lpagery_publish_date );
        if ( $dateTime instanceof DateTime ) {
            return $dateTime->format( "Y-m-d H:i:s" );
        }
        return $publish_date_from_dashboard;
    }

    private function getISODate( $dateString ) {
        try {
            $dateTime = new DateTime($dateString);
            return $dateTime;
        } catch ( \Throwable $ex ) {
            error_log( $ex->getMessage() );
            return false;
        }
    }

    public function lpagery_get_content( $json_data, $content_from_template ) {
        return $content_from_template;
    }

}
