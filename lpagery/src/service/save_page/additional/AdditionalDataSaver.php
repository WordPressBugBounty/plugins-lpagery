<?php

namespace LPagery\service\save_page\additional;


use LPagery\model\Params;
use LPagery\service\taxonomies\TaxonomySaveHandler;
use WP_Post;

class AdditionalDataSaver
{
    private static ?AdditionalDataSaver $instance = null;
    private PagebuilderHandler $pagebuilderHandler;
    private SeoPluginHandler $seoPluginHandler;
    private WpmlHandler $wpmlHandler;
    private FifuHandler $fifuHandler;
    private ?TaxonomySaveHandler $taxonomyHandler;
    private MetaDataHandler $metaDataHandler;

    public function __construct(PagebuilderHandler $pagebuilderHandler, SeoPluginHandler $seoPluginHandler, WpmlHandler $wpmlHandler, FifuHandler $fifuHandler, ?TaxonomySaveHandler $taxonomyHandler, MetaDataHandler $metaDataHandler)
    {
        $this->pagebuilderHandler = $pagebuilderHandler;
        $this->seoPluginHandler = $seoPluginHandler;
        $this->wpmlHandler = $wpmlHandler;
        $this->fifuHandler = $fifuHandler;
        $this->taxonomyHandler = $taxonomyHandler;
        $this->metaDataHandler = $metaDataHandler;
    }

    public static function get_instance(PagebuilderHandler $pagebuilderHandler, SeoPluginHandler $seoPluginHandler, WpmlHandler $wpmlHandler, FifuHandler $fifuHandler, ?TaxonomySaveHandler $taxonomyHandler, MetaDataHandler $metaDataHandler): AdditionalDataSaver
    {
        if (null === self::$instance) {
            self::$instance = new self($pagebuilderHandler, $seoPluginHandler, $wpmlHandler, $fifuHandler,
                $taxonomyHandler, $metaDataHandler);
        }
        return self::$instance;
    }

    public function saveAdditionalData(int $post_id, WP_Post $template_post, $created_process_post_id, Params $params, bool $shouldContentBeUpdated)
    {

        if ($shouldContentBeUpdated) {
            $this->metaDataHandler->lpagery_copy_post_meta_info($post_id, $template_post, array("_lpagery_page_source",
                "_lpagery_data"), $params);
            delete_post_meta($post_id, "_lpagery_process_post_id");
            add_post_meta($post_id, "_lpagery_process_post_id", $created_process_post_id);
            $this->pagebuilderHandler->lpagery_handle_pagebuilder($template_post->ID, $post_id, $params);
            $this->seoPluginHandler->lpagery_handle_seo_plugin($template_post->ID, $post_id, $params);
            $this->wpmlHandler->lpagery_handle_wpml($template_post->ID, $post_id);
            $this->fifuHandler->lpagery_handle_fifu($post_id, $params->raw_data);
        }
        if (lpagery_fs()->is_plan_or_trial__premium_only("standard") && $this->taxonomyHandler) {
            $this->taxonomyHandler->lpagery_set_taxonomies($params, $post_id);
        }

    }
}