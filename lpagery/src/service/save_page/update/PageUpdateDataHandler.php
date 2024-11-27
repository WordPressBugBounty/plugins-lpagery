<?php

namespace LPagery\service\save_page\update;

use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\save_page\PageSaver;
use LPagery\service\save_page\PostFieldProvider;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\data\LPageryDao;
use LPagery\model\PageCreationDashboardSettings;

class PageUpdateDataHandler
{
    private static $instance;
    private PageSaver $pageSaver;
    private LPageryDao $lpageryDao;
    private InputParamProvider $inputParamProvider;
    private SubstitutionHandler $substitutionHandler;
    private DynamicPageAttributeHandler $dynamicPageAttributeHandler;

    public function __construct(LPageryDao $lpageryDao, InputParamProvider $inputParamProvider, SubstitutionHandler $substitutionHandler, PageSaver $pageSaver, DynamicPageAttributeHandler $dynamicPageAttributeHandler)
    {
        $this->lpageryDao = $lpageryDao;
        $this->inputParamProvider = $inputParamProvider;
        $this->substitutionHandler = $substitutionHandler;
        $this->pageSaver = $pageSaver;
        $this->dynamicPageAttributeHandler = $dynamicPageAttributeHandler;
    }


    public static function get_instance(LPageryDao $lpageryDao, InputParamProvider $inputParamProvider, SubstitutionHandler $substitutionHandler, PageSaver $pageSaver, DynamicPageAttributeHandler $dynamicPageAttributeHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($lpageryDao, $inputParamProvider, $substitutionHandler, $pageSaver,
                $dynamicPageAttributeHandler);
        }
        return self::$instance;
    }

    public function lpagery_get_post_to_be_updated(string $slug, int $process_id)
    {
        if (!$process_id) {
            return null;
        }

        $existing_post_by_slug = $this->lpageryDao->lpagery_get_existing_post_by_slug_in_process($process_id, $slug);
        if (!empty($existing_post_by_slug)) {
            return $existing_post_by_slug;
        }

        return null;

    }

    public function getSlugToBeUpdated( $element, $process_id): ?string
    {
        $process_data = $this->lpageryDao->lpagery_get_process_by_id($process_id);
        if (!$process_data) {
            return null;
        }
        $slug = maybe_unserialize($process_data->data)["slug"];
        $params = $this->inputParamProvider->lpagery_get_input_params_without_images($element);
        $slug = $this->substitutionHandler->lpagery_substitute($params, $slug);
        $slug = sanitize_title($slug);
        return $slug;
    }

    public function update_post_design($post_id)
    {
        $process_post_data = $this->lpageryDao->lpagery_get_process_post_data($post_id);
        if (!$process_post_data) {
            return;
        }
        $process_id = $process_post_data->process_id;
        $process_data = $this->lpageryDao->lpagery_get_process_by_id($process_id);

        $templatePath = $process_data->post_id;
        $template_post = get_post($templatePath);
        $post_to_be_updated = get_post($post_id);

        $unserializedPageCreationConfig = maybe_unserialize($process_data->data);
        $process_settings = PageCreationDashboardSettings::build_from_array($unserializedPageCreationConfig);

        $unserializedProcessPostData = maybe_unserialize($process_post_data->data);

        $params = $this->inputParamProvider->lpagery_provide_input_params($unserializedProcessPostData, $process_id,
            $templatePath, $process_settings);
        $postSaveHelper = new PostFieldProvider($template_post, $params, $this->substitutionHandler,
            $post_to_be_updated, $this->dynamicPageAttributeHandler);
        $result = $this->pageSaver->savePage($template_post, $params, $postSaveHelper, [], $post_to_be_updated);
    }


}
