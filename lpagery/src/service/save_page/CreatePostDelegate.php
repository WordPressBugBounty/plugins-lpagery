<?php

namespace LPagery\service\save_page;

use Exception;
use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\save_page\update\PageUpdateDataHandler;
use LPagery\service\save_page\update\ShouldPageBeUpdatedChecker;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\data\LPageryDao;
use LPagery\model\PageCreationDashboardSettings;
class CreatePostDelegate {
    private LPageryDao $lpageryDao;

    private InputParamProvider $inputParamProvider;

    private SubstitutionHandler $substitutionHandler;

    private DynamicPageAttributeHandler $dynamicPageAttributeHandler;

    private PageSaver $pageSaver;

    private PageUpdateDataHandler $pageUpdateDataHandler;

    private SubstitutionDataPreparator $substitutionDataPreparator;

    private ShouldPageBeUpdatedChecker $shouldPageBeUpdatedChecker;

    public function __construct(
        LPageryDao $lpageryDao,
        InputParamProvider $inputParamProvider,
        SubstitutionHandler $substitutionHandler,
        DynamicPageAttributeHandler $dynamicPageAttributeHandler,
        PageSaver $pageSaver,
        PageUpdateDataHandler $pageUpdateDataHandler,
        SubstitutionDataPreparator $substitutionDataPreparator,
        ShouldPageBeUpdatedChecker $shouldPageBeUpdatedChecker
    ) {
        $this->lpageryDao = $lpageryDao;
        $this->inputParamProvider = $inputParamProvider;
        $this->substitutionHandler = $substitutionHandler;
        $this->dynamicPageAttributeHandler = $dynamicPageAttributeHandler;
        $this->pageSaver = $pageSaver;
        $this->pageUpdateDataHandler = $pageUpdateDataHandler;
        $this->substitutionDataPreparator = $substitutionDataPreparator;
        $this->shouldPageBeUpdatedChecker = $shouldPageBeUpdatedChecker;
    }

    private static $instance;

    public static function get_instance(
        LPageryDao $lpageryDao,
        InputParamProvider $inputParamProvider,
        SubstitutionHandler $substitutionHandler,
        DynamicPageAttributeHandler $dynamicPageAttributeHandler,
        PageSaver $pageSaver,
        PageUpdateDataHandler $pageUpdateDataHandler,
        SubstitutionDataPreparator $substitutionDataPreparator,
        ShouldPageBeUpdatedChecker $shouldPageBeUpdatedChecker
    ) {
        if ( null === self::$instance ) {
            self::$instance = new self(
                $lpageryDao,
                $inputParamProvider,
                $substitutionHandler,
                $dynamicPageAttributeHandler,
                $pageSaver,
                $pageUpdateDataHandler,
                $substitutionDataPreparator,
                $shouldPageBeUpdatedChecker
            );
        }
        return self::$instance;
    }

    /**
     * @throws Exception
     */
    public function lpagery_create_post( $REQUEST_PAYLOAD, $processed_slugs, $operations = array("create", "update") ) {
        $process_id = (int) ($REQUEST_PAYLOAD['process_id'] ?? 0);
        if ( $process_id <= 0 ) {
            throw new Exception("Process ID must be set. This might be an issue with your Database-Version. Please check and consider updating the Database-Version");
        }
        $process_by_id = $this->lpageryDao->lpagery_get_process_by_id( $process_id );
        $templatePath = $process_by_id->post_id;
        $template_post = get_post( $templatePath );
        if ( !$template_post ) {
            throw new Exception("Post with ID " . $templatePath . " not found");
        }
        $data = $REQUEST_PAYLOAD['data'];
        if ( is_string( $data ) ) {
            $json_decode = $this->substitutionDataPreparator->prepare_data( $data );
        } else {
            $json_decode = $data;
        }
        if ( !is_array( $json_decode ) || empty( $json_decode ) ) {
            return array(
                "template_post" => $template_post,
                "mode"          => 'ignored',
            );
        }
        $categories = array();
        $tags = array();
        $status_from_process = 'publish';
        $status_from_dashboard = sanitize_text_field( $REQUEST_PAYLOAD['status'] ?? '-1' );
        $slug = lpagery_sanitize_title_with_dashes( $template_post->post_title );
        $parent_path = 0;
        $datetime = null;
        $pageCreationSettings = new PageCreationDashboardSettings();
        $pageCreationSettings->parent = $parent_path;
        $pageCreationSettings->categories = $categories;
        $pageCreationSettings->tags = $tags;
        $pageCreationSettings->slug = $slug;
        $pageCreationSettings->status_from_process = $status_from_process;
        $pageCreationSettings->publish_datetime = $datetime;
        $pageCreationSettings->status_from_dashboard = $status_from_dashboard;
        $params = $this->inputParamProvider->lpagery_provide_input_params(
            $json_decode,
            $process_id,
            $template_post->ID,
            $pageCreationSettings
        );
        $postSaveHelper = new PostFieldProvider(
            $template_post,
            $params,
            $this->substitutionHandler,
            null,
            $this->dynamicPageAttributeHandler
        );
        $result = $this->pageSaver->savePage(
            $template_post,
            $params,
            $postSaveHelper,
            $processed_slugs,
            null
        );
        return array(
            "template_post" => $template_post,
            "mode"          => $result->mode,
            "slug"          => $slug,
            "replaced_slug" => $result->slug,
        );
    }

}
