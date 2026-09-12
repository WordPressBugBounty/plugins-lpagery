<?php

namespace LPagery\service\save_page;

use Exception;
use LPagery\data\repository\GeneratedPageRepository;
use LPagery\model\Params;
use LPagery\service\caching\PurgeCachingPluginsService;
use LPagery\service\save_page\additional\AdditionalDataSaver;
use LPagery\service\save_page\update\PageUpdateChecker;
use WP_Post;

class PageSaver
{
    private GeneratedPageRepository $generatedPageRepository;
    private AdditionalDataSaver $additionalDataSaver;
    private ?PageUpdateChecker $shouldPageBeUpdatedChecker;
    private PurgeCachingPluginsService $purgeCachingPluginsService;


    public function __construct(GeneratedPageRepository $generatedPageRepository, AdditionalDataSaver $additionalDataSaver, ?PageUpdateChecker $shouldPageBeUpdatedChecker, PurgeCachingPluginsService $purgeCachingPluginsService)
    {
        $this->generatedPageRepository = $generatedPageRepository;
        $this->additionalDataSaver = $additionalDataSaver;
        $this->shouldPageBeUpdatedChecker = $shouldPageBeUpdatedChecker;
        $this->purgeCachingPluginsService = $purgeCachingPluginsService;
    }

    /**
     * @throws Exception
     */
    public function savePage(WP_Post $template_post, Params $params, PostFieldProvider $postFieldProvider, array $processed_slugs, ?WP_Post $post_to_be_updated): SavePageResult
    {
        $slug = $postFieldProvider->get_slug();
        $parent = $postFieldProvider->get_parent();
        $json_decode = $params->raw_data;
        $process_id = $params->process_id;
        $client_generated_slug = $params->settings->client_generated_slug;

        $cached_slug = CreatedPageCacheValue::create($params, $slug, $parent);
        $slug_already_processed = $processed_slugs && count($processed_slugs) > 0 && (in_array($cached_slug->value,
                $processed_slugs));

        if (($slug_already_processed)) {
            return SavePageResult::create("ignored", "duplicated_slug", $slug,$params, $parent);
        }
        $ignore_is_set = isset($json_decode["lpagery_ignore"]) && filter_var($json_decode["lpagery_ignore"],
                FILTER_VALIDATE_BOOLEAN);

        if ($ignore_is_set) {
            return SavePageResult::create("ignored", "lpagery_ignore", $slug, $params, $parent);
        }

        $transient_key = "lpagery_$process_id" . "_" . $slug;
        $process_slug_transient = get_transient($transient_key);
        if ($process_slug_transient) {
            error_log("LPagery Ignoring Post is already processing $slug");
            return SavePageResult::create("ignored", "slug_already_processing", $slug, $params, $parent);
        }

        // Single acquire of the per-slug processing lock. Everything past this point runs
        // inside the try/finally below so the lock is released on EXACTLY ONE path — the
        // outer finally — no matter how savePage exits (return, throw, or commit).
        set_transient($transient_key, true, 10);

        try {
            $create_mode = !$post_to_be_updated;
            // Without the Extended PageUpdateChecker (free and lapsed plans) the only update that
            // reaches here is a forced rewrite - Materialize (ADR 0019) - and it must copy the
            // template meta along with the content, so the forced flag decides on its own.
            $shouldContentBeUpdated = $create_mode || $params->force_update_content;

            // Live Mode persists a Stub Post: the content fields stay empty and are resolved
            // from the Template Page at render time (ADR 0010). Physical scalars are still
            // substituted below exactly as in Classic Mode.
            $is_live = $params->render_mode === 'live';

            if (!$create_mode && $this->shouldPageBeUpdatedChecker) {
                $shouldPageBeUpdated = $this->shouldPageBeUpdatedChecker->should_page_be_updated($template_post,
                    $post_to_be_updated, $params);
                if(!$shouldPageBeUpdated) {
                    // Returns before START TRANSACTION: no transaction runs, and the lock is
                    // still released by the outer finally.
                    return SavePageResult::create("ignored", "data_did_not_change", $slug, $params, $parent);
                }
                $shouldContentBeUpdated = $this->shouldPageBeUpdatedChecker->should_content_be_updated($template_post,
                    $post_to_be_updated, $params);

                $new_post = ["ID" => $post_to_be_updated->ID,
                    'post_parent' => $parent,
                    'post_name' => $postFieldProvider->get_slug(),
                    'post_author' => $postFieldProvider->get_author($process_id),
                    'post_status' => $postFieldProvider->get_status( $postFieldProvider->get_publish_datetime())];

                if ($shouldContentBeUpdated) {
                    $new_post['post_content'] = $is_live ? '' : $postFieldProvider->get_content();
                    $new_post['post_content_filtered'] = $is_live ? '' : $postFieldProvider->get_content_filtered();
                    $new_post['post_title'] = $postFieldProvider->get_title();
                    $new_post['post_excerpt'] = $postFieldProvider->get_excerpt();
                }

            } else {
                $new_post = ["ID" => $post_to_be_updated ? $post_to_be_updated->ID : null,
                    'post_content' => $is_live ? '' : $postFieldProvider->get_content(),
                    'post_content_filtered' => $is_live ? '' : $postFieldProvider->get_content_filtered(),
                    'post_title' => $postFieldProvider->get_title(),
                    'post_excerpt' => $postFieldProvider->get_excerpt(),
                    'post_type' => $template_post->post_type,
                    'comment_status' => $template_post->comment_status,
                    'ping_status' => $template_post->ping_status,
                    'post_password' => $template_post->post_password,
                    'post_parent' => $parent,
                    'post_name' => $postFieldProvider->get_slug(),
                    'post_mime_type' => $template_post->post_mime_type,
                    'post_status' => $postFieldProvider->get_status( $postFieldProvider->get_publish_datetime()),
                    'post_author' => $postFieldProvider->get_author($process_id)];
            }

            $new_post['post_date'] =  $postFieldProvider->get_publish_datetime();;
            $new_post['post_date_gmt'] = get_gmt_from_date( $postFieldProvider->get_publish_datetime());


            global $wpdb;
            $wpdb->query('START TRANSACTION');
            // Single rollback decision point: the inner finally rolls back unless the body
            // reached COMMIT and set $committed. Any early return or throw below leaves
            // $committed false, so it rolls back exactly once.
            $committed = false;
            try {
                $post_id = $create_mode ? wp_insert_post($new_post, true) : wp_update_post($new_post, true);

                if (is_wp_error($post_id)) {
                    error_log($post_id->get_error_message());
                    throw new Exception(json_encode($post_id->get_all_error_data()));
                }

                $result = $this->generatedPageRepository->add_post_to_process($params, $post_id, $template_post->ID, $slug, $shouldContentBeUpdated, $parent, $postFieldProvider->get_parent_search_term(), $client_generated_slug, $params->settings->hashed_payload);
                if ($result["error"]) {
                    error_log("LPagery Rolling Back Transaction During creation slug : $slug, Process : $process_id " . $result["error"]);
                    return SavePageResult::create("ignored","other_page_with_slug_exists_in_set", $slug, $params, $parent);
                }
                $created_process_post_id = $result["created_id"];
                // Preserve the pre-refactor diagnostic on the additional-data failure path (the
                // rollback itself stays in the single finally below; this catch only logs+rethrows,
                // matching the original per-path error_log without touching other exit paths).
                try {
                    $this->additionalDataSaver->saveAdditionalData($post_id, $template_post, $created_process_post_id, $params,
                        $shouldContentBeUpdated);
                } catch (\Throwable $e) {
                    error_log("LPagery Rolling Back Transaction During creation slug : $slug, Process : $process_id " . $e->getMessage());
                    throw $e;
                }

                $wpdb->query('COMMIT');
                $committed = true;
            } finally {
                if (!$committed) {
                    $wpdb->query('ROLLBACK');
                }
            }

            $this->purgeCachingPluginsService->purge_caching_plugins($post_id);

            if ($create_mode) {
                return SavePageResult::create("created", "created", $slug, $params, $parent);
            }
            return SavePageResult::create("updated", $shouldContentBeUpdated ? 'content_updated' : 'config_updated',  $slug, $params, $parent);
        } finally {
            delete_transient($transient_key);
        }
    }


}