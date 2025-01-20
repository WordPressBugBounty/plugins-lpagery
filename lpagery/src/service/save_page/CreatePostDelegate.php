<?php

namespace LPagery\service\save_page;

use Exception;
use LPagery\data\LPageryDao;
use LPagery\model\PageCreationDashboardSettings;
use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\save_page\update\PageUpdateDataHandler;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\utils\Utils;
class CreatePostDelegate {
    private LPageryDao $lpageryDao;

    private InputParamProvider $inputParamProvider;

    private SubstitutionHandler $substitutionHandler;

    private DynamicPageAttributeHandler $dynamicPageAttributeHandler;

    private PageSaver $pageSaver;

    private ?PageUpdateDataHandler $pageUpdateDataHandler;

    private SubstitutionDataPreparator $substitutionDataPreparator;

    public function __construct(
        LPageryDao $lpageryDao,
        InputParamProvider $inputParamProvider,
        SubstitutionHandler $substitutionHandler,
        DynamicPageAttributeHandler $dynamicPageAttributeHandler,
        PageSaver $pageSaver,
        ?PageUpdateDataHandler $pageUpdateDataHandler,
        SubstitutionDataPreparator $substitutionDataPreparator
    ) {
        $this->lpageryDao = $lpageryDao;
        $this->inputParamProvider = $inputParamProvider;
        $this->substitutionHandler = $substitutionHandler;
        $this->dynamicPageAttributeHandler = $dynamicPageAttributeHandler;
        $this->pageSaver = $pageSaver;
        $this->pageUpdateDataHandler = $pageUpdateDataHandler;
        $this->substitutionDataPreparator = $substitutionDataPreparator;
    }

    private static $instance;

    public static function get_instance(
        LPageryDao $lpageryDao,
        InputParamProvider $inputParamProvider,
        SubstitutionHandler $substitutionHandler,
        DynamicPageAttributeHandler $dynamicPageAttributeHandler,
        PageSaver $pageSaver,
        ?PageUpdateDataHandler $pageUpdateDataHandler,
        SubstitutionDataPreparator $substitutionDataPreparator
    ) {
        if ( null === self::$instance ) {
            self::$instance = new self(
                $lpageryDao,
                $inputParamProvider,
                $substitutionHandler,
                $dynamicPageAttributeHandler,
                $pageSaver,
                $pageUpdateDataHandler,
                $substitutionDataPreparator
            );
        }
        return self::$instance;
    }

    /**
     * @throws Exception
     */
    public function lpagery_create_post( $REQUEST_PAYLOAD, $processed_slugs, $operations = array("create", "update") ) : SavePageResult {
        if ( !defined( 'DOING_LPAGERY_CREATION' ) ) {
            define( 'DOING_LPAGERY_CREATION', true );
        }
        $process_id = (int) ($REQUEST_PAYLOAD['process_id'] ?? 0);
        $page_id_to_be_updated = $REQUEST_PAYLOAD["page_id_to_be_updated"] ?? null;
        if ( $process_id <= 0 && !$page_id_to_be_updated ) {
            throw new Exception("Process ID must be set. This might be an issue with your Database-Version. Please check and consider updating the Database-Version");
        }
        if ( $process_id ) {
            $process_by_id = $this->lpageryDao->lpagery_get_process_by_id( $process_id );
            $templatePath = $process_by_id->post_id;
        } else {
            $templatePath = ( isset( $REQUEST_PAYLOAD['update_template_id'] ) ? intval( $REQUEST_PAYLOAD['update_template_id'] ) : null );
            $process_by_id = $this->lpageryDao->lpagery_get_process_by_created_post_id( $page_id_to_be_updated );
            $process_id = $process_by_id->id;
        }
        if ( $process_id <= 0 ) {
            throw new Exception("Process ID must be not found");
        }
        $template_post = get_post( $templatePath );
        if ( !$template_post ) {
            throw new Exception("Post with ID " . $templatePath . " not found");
        }
        $force_update_content = filter_var( $REQUEST_PAYLOAD["force_update_content"] ?? false, FILTER_VALIDATE_BOOLEAN );
        $overwrite_manual_changes = filter_var( $REQUEST_PAYLOAD["overwrite_manual_changes"] ?? false, FILTER_VALIDATE_BOOLEAN );
        $data = $REQUEST_PAYLOAD['data'] ?? null;
        if ( isset( $REQUEST_PAYLOAD["page_id_to_be_updated"] ) && !$data ) {
            $process_post_data = $this->lpageryDao->lpagery_get_process_post_data( intval( $REQUEST_PAYLOAD["page_id_to_be_updated"] ) );
            $data = maybe_unserialize( $process_post_data->data );
        }
        if ( is_string( $data ) ) {
            $json_decode = $this->substitutionDataPreparator->prepare_data( $data );
        } else {
            $json_decode = $data;
        }
        $taxonomy_terms = array();
        $status_from_process = 'publish';
        $status_from_dashboard = sanitize_text_field( $REQUEST_PAYLOAD['status'] ?? '-1' );
        $slug = Utils::lpagery_sanitize_title_with_dashes( $template_post->post_title );
        $parent_path = 0;
        $datetime = null;
        $pageCreationSettings = new PageCreationDashboardSettings();
        $pageCreationSettings->parent = $parent_path;
        $pageCreationSettings->taxonomy_terms = $taxonomy_terms;
        $pageCreationSettings->slug = $slug;
        $pageCreationSettings->status_from_process = $status_from_process;
        $pageCreationSettings->publish_datetime = $datetime;
        $pageCreationSettings->status_from_dashboard = $status_from_dashboard;
        $params = $this->inputParamProvider->lpagery_provide_input_params(
            $json_decode,
            $process_id,
            $template_post->ID,
            $pageCreationSettings,
            $force_update_content,
            $overwrite_manual_changes
        );
        if ( in_array( "create", $operations ) ) {
            $postSaveHelper = new PostFieldProvider(
                $template_post,
                $params,
                $this->substitutionHandler,
                null,
                $this->dynamicPageAttributeHandler
            );
            return $this->pageSaver->savePage(
                $template_post,
                $params,
                $postSaveHelper,
                $processed_slugs,
                null
            );
        }
        return new SavePageResult("ignored", "unknown_operation", $this->pageUpdateDataHandler->getSlugToBeUpdated( $json_decode, $process_id ));
    }

    private function get_taxonomy_terms( $process_config ) {
        if ( !array_key_exists( 'taxonomy_terms', $process_config ) ) {
            return [
                "category" => $process_config["categories"],
                "post_tag" => $process_config["tags"],
            ];
        }
        return $process_config["taxonomy_terms"];
    }

}
