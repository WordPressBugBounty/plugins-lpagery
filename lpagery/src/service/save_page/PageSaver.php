<?php

namespace LPagery\service\save_page;

use Exception;
use LPagery\data\LPageryDao;
use LPagery\model\Params;
use LPagery\service\caching\PurgeCachingPluginsService;
use LPagery\service\save_page\additional\AdditionalDataSaver;
use LPagery\service\save_page\update\ShouldPageBeUpdatedChecker;
use WP_Post;

class PageSaver
{
    private static ?PageSaver $instance = null;
    private LPageryDao $lpageryDao;
    private AdditionalDataSaver $additionalDataSaver;
    private ?ShouldPageBeUpdatedChecker $shouldPageBeUpdatedChecker;
    private PurgeCachingPluginsService $purgeCachingPluginsService;


    public function __construct(LPageryDao $lpageryDao, AdditionalDataSaver $additionalDataSaver, ?ShouldPageBeUpdatedChecker $shouldPageBeUpdatedChecker, PurgeCachingPluginsService $purgeCachingPluginsService)
    {
        $this->lpageryDao = $lpageryDao;
        $this->additionalDataSaver = $additionalDataSaver;
        $this->shouldPageBeUpdatedChecker = $shouldPageBeUpdatedChecker;
        $this->purgeCachingPluginsService = $purgeCachingPluginsService;
    }

    public static function get_instance(LPageryDao $lpageryDao, AdditionalDataSaver $additionalDataSaver, ?ShouldPageBeUpdatedChecker $shouldPageBeUpdatedChecker, PurgeCachingPluginsService $purgeCachingPluginsService)
    {
        if (null === self::$instance) {
            self::$instance = new self($lpageryDao, $additionalDataSaver, $shouldPageBeUpdatedChecker, $purgeCachingPluginsService);
        }
        return self::$instance;
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

        set_transient($transient_key, true, 10);
        $create_mode = !$post_to_be_updated;
        $shouldContentBeUpdated = $create_mode;

        if (!$create_mode && $this->shouldPageBeUpdatedChecker) {
            $shouldPageBeUpdated = $this->shouldPageBeUpdatedChecker->should_page_be_updated($template_post,
                $post_to_be_updated, $params);
            if(!$shouldPageBeUpdated) {
                delete_transient($transient_key);
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
                $new_post['post_content'] = $postFieldProvider->get_content();
                $new_post['post_content_filtered'] = $postFieldProvider->get_content_filtered();
                $new_post['post_title'] = $postFieldProvider->get_title();
                $new_post['post_excerpt'] = $postFieldProvider->get_excerpt();
            }

        } else {
            $new_post = ["ID" => $post_to_be_updated ? $post_to_be_updated->ID : null,
                'post_content' => $postFieldProvider->get_content(),
                'post_content_filtered' => $postFieldProvider->get_content_filtered(),
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
        if ($create_mode) {
            $post_id = wp_insert_post($new_post, true);

        } else {
            $post_id = wp_update_post($new_post, true);
        };

        if (is_wp_error($post_id)) {
            error_log($post_id->get_error_message());
            $wpdb->query('ROLLBACK');
            delete_transient($transient_key);
            throw new Exception(json_encode($post_id->get_all_error_data()));
        }
        try {
            $result = $this->lpageryDao->lpagery_add_post_to_process($params, $post_id, $template_post->ID, $slug, $shouldContentBeUpdated, $parent, $postFieldProvider->get_parent_search_term(), $client_generated_slug);
            if ($result["error"]) {
                error_log("LPagery Rolling Back Transaction During creation slug : $slug, Process : $process_id " . $result["error"]);
                $wpdb->query('ROLLBACK');
                delete_transient($transient_key);
                return SavePageResult::create("ignored","other_page_with_slug_exists_in_set", $slug, $params, $parent);
            }
            $created_process_post_id = $result["created_id"];
            $this->additionalDataSaver->saveAdditionalData($post_id, $template_post, $created_process_post_id, $params,
                $shouldContentBeUpdated);

        } catch (\Throwable $e) {
            error_log("LPagery Rolling Back Transaction During creation slug : $slug, Process : $process_id " . $e->getMessage());
            $wpdb->query('ROLLBACK');
            delete_transient($transient_key);
            throw $e;
        }

        $wpdb->query('COMMIT');
        delete_transient($transient_key);
        $this->purgeCachingPluginsService->purge_caching_plugins($post_id);

        if ($create_mode) {
            return SavePageResult::create("created", "created", $slug, $params, $parent);
        }
        return SavePageResult::create("updated", $shouldContentBeUpdated ? 'content_updated' : 'config_updated',  $slug, $params, $parent);
    }


}