<?php

namespace LPagery\service\save_page\additional;


use LPagery\data\repository\GeneratedPageRepository;
use LPagery\model\Params;
use LPagery\multilingual\MultilingualPlugin;
use LPagery\service\taxonomies\TaxonomyAssigner;
use WP_Post;

class AdditionalDataSaver
{
    private PagebuilderHandler $pagebuilderHandler;
    private SeoPluginHandler $seoPluginHandler;
    private ?MultilingualPlugin $multilingualPlugin;
    private FifuHandler $fifuHandler;
    private ?TaxonomyAssigner $taxonomyHandler;
    private MetaDataHandler $metaDataHandler;
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(PagebuilderHandler $pagebuilderHandler, SeoPluginHandler $seoPluginHandler, ?MultilingualPlugin $multilingualPlugin, FifuHandler $fifuHandler, ?TaxonomyAssigner $taxonomyHandler, MetaDataHandler $metaDataHandler, GeneratedPageRepository $generatedPageRepository)
    {
        $this->pagebuilderHandler = $pagebuilderHandler;
        $this->seoPluginHandler = $seoPluginHandler;
        $this->multilingualPlugin = $multilingualPlugin;
        $this->fifuHandler = $fifuHandler;
        $this->taxonomyHandler = $taxonomyHandler;
        $this->metaDataHandler = $metaDataHandler;
        $this->generatedPageRepository = $generatedPageRepository;
    }

    public function saveAdditionalData(int $post_id, WP_Post $template_post, $created_process_post_id, Params $params, bool $shouldContentBeUpdated)
    {
        $is_live = $params->render_mode === 'live';

        if ($shouldContentBeUpdated) {
            if ($is_live) {
                // Live Mode persists only the stub scalar/tracking metas (ADR 0010): no generic
                // template meta copy, and the builder/SEO/FIFU handlers are skipped so the
                // content-bearing metas stay on the Template Page and resolve at render time.
                $this->metaDataHandler->lpagery_copy_stub_meta_info($post_id, $template_post, $params);
            } else {
                $this->metaDataHandler->lpagery_copy_post_meta_info($post_id, $template_post, array("_lpagery_page_source",
                    "_lpagery_data"), $params);
            }
            delete_post_meta($post_id, "_lpagery_process_post_id");
            add_post_meta($post_id, "_lpagery_process_post_id", $created_process_post_id);
            if (!$is_live) {
                $this->pagebuilderHandler->lpagery_handle_pagebuilder($template_post->ID, $post_id, $params);
                $this->seoPluginHandler->lpagery_handle_seo_plugin($template_post->ID, $post_id, $params);
                $this->fifuHandler->lpagery_handle_fifu($post_id, $params->raw_data);
            }
        }

        // The Page Language is inherited from the Template Page and re-asserted on every pass, in
        // both Render Modes: a Live Mode Stub Post is a real post and has to carry it too. A
        // hand-changed language is overwritten and is deliberately not a Manual Change.
        $page_language = $this->multilingualPlugin !== null ? $this->multilingualPlugin->get_post_language($template_post->ID) : null;
        if ($this->multilingualPlugin !== null && $page_language !== null) {
            $this->multilingualPlugin->set_post_language($post_id, $page_language);
        }

        $created_term_ids = array();
        if (lpagery_fs()->is_plan_or_trial__premium_only("standard") && $this->taxonomyHandler) {
            $created_term_ids = $this->taxonomyHandler->lpagery_set_taxonomies($params, $post_id);
        }

        // Term-language sync runs after the terms are attached, so it sees the final term list.
        if ($this->multilingualPlugin !== null && $page_language !== null) {
            $this->multilingualPlugin->sync_post_terms_language($post_id, $page_language, $created_term_ids);
        }

        // Per-page Render Mode marker (ADR 0010) so the renderer can decide per page and mixed sets
        // render correctly. Written on every generation/update through the repository's one write
        // seam {@see GeneratedPageRepository::set_render_mode()}; never backfilled.
        $this->generatedPageRepository->set_render_mode($post_id,
            $is_live ? GeneratedPageRepository::RENDER_MODE_LIVE : GeneratedPageRepository::RENDER_MODE_CLASSIC);
    }
}
