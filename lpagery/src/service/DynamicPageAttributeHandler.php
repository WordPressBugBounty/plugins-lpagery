<?php

namespace LPagery\service;

use DateTime;
use LPagery\service\settings\SettingsController;
use LPagery\data\LPageryDao;
use LPagery\model\BaseParams;
use Throwable;
class DynamicPageAttributeHandler {
    private static ?DynamicPageAttributeHandler $instance = null;

    private SettingsController $settingsController;

    private LPageryDao $lpageryDao;

    private FindPostService $findPostService;

    public function __construct( SettingsController $settingsController, LPageryDao $lpageryDao, FindPostService $findPostService ) {
        $this->settingsController = $settingsController;
        $this->lpageryDao = $lpageryDao;
        $this->findPostService = $findPostService;
    }

    public static function get_instance( SettingsController $settingsController, LPageryDao $lpageryDao, FindPostService $findPostService ) {
        if ( null === self::$instance ) {
            self::$instance = new self($settingsController, $lpageryDao, $findPostService);
        }
        return self::$instance;
    }

    public function lpagery_get_author( $process_id, $json_data ) {
        return 0;
    }

    public function lpagery_get_status( $json_data, $status_from_dashboard ) {
        return "publish";
    }

    public function lpagery_get_parent( BaseParams $params, $post_type, $parent_id_from_dashboard ) {
        $json_data = $params->raw_data ?? array();
        if ( !array_key_exists( "lpagery_parent", $json_data ) ) {
            error_log( "Parent not found in JSON data" );
            error_log( json_encode( $json_data ) );
            return $this->lpageryDao->lpagery_find_post_by_id( $parent_id_from_dashboard );
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
            return $this->lpageryDao->lpagery_find_post_by_id( $template_id_from_dashboard );
        }
        $lpagery_template_term = $json_data["lpagery_template"];
        return $this->findPostService->lpagery_find_post_or_default(
            $params,
            $lpagery_template_term,
            $template_id_from_dashboard,
            $post_type
        );
    }

    public function lpagery_get_publish_date( $json_data, $publish_date_from_dashboard ) : ?string {
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
