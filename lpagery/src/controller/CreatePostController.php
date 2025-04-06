<?php

namespace LPagery\controller;

use LPagery\data\LPageryDao;
use LPagery\service\media\AttachmentSearchService;
use LPagery\service\media\CacheableAttachmentSearchService;
use LPagery\service\save_page\CreatePostDelegate;
use LPagery\service\settings\SettingsController;
use LPagery\utils\MemoryUtils;
use WP_Error;
use WP_REST_Request;

if (!defined('TEST_RUNNING')) {
    include_once(plugin_dir_path(__FILE__) . '/../utils/IncludeWordpressFiles.php');
}

class CreatePostController
{

    private static $instance;
    private CreatePostDelegate $createPostDelegate;
    private LPageryDao $LPageryDao;
    private SettingsController $settingsController;
    private ?CacheableAttachmentSearchService $cacheableAttachmentSearchService;

    public function __construct(CreatePostDelegate $createPostDelegate, LPageryDao $LPageryDao, SettingsController $settingsController, ?CacheableAttachmentSearchService $cacheableAttachmentSearchService)
    {
        $this->createPostDelegate = $createPostDelegate;
        $this->LPageryDao = $LPageryDao;
        $this->settingsController = $settingsController;
        $this->cacheableAttachmentSearchService = $cacheableAttachmentSearchService;
    }


    public static function get_instance(CreatePostDelegate $createPostDelegate, LPageryDao $LPageryDao, SettingsController $settingsController, ?CacheableAttachmentSearchService $cacheableAttachmentSearchService): CreatePostController
    {
        if (null === self::$instance) {
            self::$instance = new self($createPostDelegate, $LPageryDao, $settingsController, $cacheableAttachmentSearchService);
        }
        return self::$instance;
    }


    public function lpagery_create_posts_rest(WP_REST_Request $request)
    {
        $secret_from_request = strval($request->get_param('secret'));
        $secret = strval(get_option('lpagery_queue_create_post_secret'));
        if (!hash_equals($secret, $secret_from_request)) {
            return new WP_Error('invalid_secret', 'Invalid secret ' . $secret_from_request, array('status' => 403));
        }

        global $wpdb;
        $queue_item_id = $request->get_param("queue_item_id");
        if (!$queue_item_id) {
            return new WP_Error('invalid_queue_item_id', 'Invalid queue_item_id', array('status' => 400));
        }

        $table_name = $wpdb->prefix . 'lpagery_sync_queue';
        $queue_items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE id = %s", $queue_item_id),
            ARRAY_A);
        if (!$queue_items) {
            return new WP_Error('queue_item_not_found', 'Queue item not found', array('status' => 404));
        }
        $queue_item = $queue_items[0];

        $creation_id = $queue_item["creation_id"];
        $transient_key = "lpagery_$creation_id";
        $processed_slugs = get_transient($transient_key);
        if (!$processed_slugs) {
            $processed_slugs = [];
        } else {
            $processed_slugs = maybe_unserialize($processed_slugs);
        }
        $queue_transient_key = 'lpagery_queue_processing' . $queue_item["id"];
        $currently_processing = get_transient($queue_transient_key);
        $already_processed = in_array($queue_item["slug"], $processed_slugs);
        if ($already_processed || $currently_processing) {
            return array("success" => true,
                "slug" => $queue_item["slug"]);
        }

        set_transient($queue_transient_key, true, 60);
        $process_id = $queue_item['process_id'];
        $process = $this->LPageryDao->lpagery_get_process_by_id($process_id);
        $google_sheet_data = maybe_unserialize($process->google_sheet_data);
        $operations = array();
        if ($google_sheet_data["add"]) {
            $operations[] = "create";
        }
        if ($google_sheet_data["update"]) {
            $operations[] = "update";
        }
        $params = [];
        $params["process_id"] = $process_id;
        $params["creation_id"] = $creation_id;
        $params["data"] = maybe_unserialize($queue_item["data"]);
        $params["force_update_content"] = ($queue_item["force_update"] ?? false) || $this->settingsController->isForceUpdateEnabled();
        $params["overwrite_manual_changes"] = ($queue_item["overwrite_manual_changes"] ?? false) || $this->settingsController->isOverwriteManualChangesEnabled();
        $params["publish_timestamp"] = $queue_item["publish_timestamp"] ?? null;
        $params["existing_page_update_action"] = $queue_item["existing_page_update_action"] ?? 'create';
        if(isset($queue_item["status_from_dashboard"])) {
            $params["status"] = $queue_item["status_from_dashboard"];
        }

        $response = $this->createPostDelegate->lpagery_create_post($params, $processed_slugs, $operations);
        if ($creation_id && $response->slug && $response->mode !== "ignored") {
            $processed_slugs[] =  $response->createdPageCacheValue->value;
            set_transient($transient_key, $processed_slugs, 60);
        }

        $replaced_slug = $response->slug;

        $result_array = array("success" => true,
            "slug" => $replaced_slug);
        delete_transient($queue_transient_key);

        return ($result_array);
    }


    function lpagery_create_posts_ajax($post_data)
    {
        $nonce_validity = check_ajax_referer('lpagery_ajax');
        $creation_id = $post_data["creation_id"];
        $is_last_page = filter_var($post_data["is_last_page"], FILTER_VALIDATE_BOOLEAN);
        $index = intval($post_data["index"]);
        if($index ==0 && $this->cacheableAttachmentSearchService) {
           $this->cacheableAttachmentSearchService->evict_cache();
        }


        $transient_key = "lpagery_$creation_id";
        $processed_slugs = get_transient($transient_key);
        if (!$processed_slugs) {
            $processed_slugs = [];
        } else {
            $processed_slugs = maybe_unserialize($processed_slugs);
        }

        $response = $this->createPostDelegate->lpagery_create_post($post_data, $processed_slugs);
        if ($creation_id && $response->slug &&  $response->createdPageCacheValue) {
            $processed_slugs[] = $response->createdPageCacheValue->value;
            if (!$is_last_page) {
                set_transient($transient_key, $processed_slugs, 60);
            }
        }

        $memory_usage = $this->getMemory_usage();
        $result_array = array("success" => true,
            "mode" => $response->mode,
            "used_memory" => $memory_usage,
            "slug" => $response->slug);

        if ($response->mode == "ignored") {
            $result_array["ignored_reason"] = $response->reason;
        }

        if ($response->mode == "created") {
            $result_array["created_reason"] = $response->reason;
        }

        if ($response->mode == "updated") {
            $result_array["updated_reason"] = $response->reason;
        }


        $result_array = $this->append_new_nonce_if_needed($nonce_validity, $result_array);
        $this->set_finished_if_last_page($post_data, $is_last_page);

        if ($is_last_page) {
            delete_transient($transient_key);
        }

        return $result_array;

    }

    /**
     * @return array
     */
    private function getMemory_usage(): array
    {
        $memory_usage = array();
        try {
            $memory_usage = MemoryUtils::lpagery_get_memory_usage();
        } catch (\Throwable $e) {
            error_log($e->getMessage());
        }
        return $memory_usage;
    }


    /**
     * @param $nonce_validity
     * @param array $result_array
     * @return array
     */
    public function append_new_nonce_if_needed($nonce_validity, array $result_array): array
    {
        if ($nonce_validity == 2) {
            $result_array["nonce"] = wp_create_nonce("lpagery_ajax");
        }
        return $result_array;
    }

    /**
     * @param $post_data
     * @param bool $is_last_page
     * @return void
     */
    private function set_finished_if_last_page($post_data, bool $is_last_page): void
    {
        try {
            if ($is_last_page) {
                $this->LPageryDao->lpagery_update_process_sync_status((int)$post_data['process_id'], "FINISHED");
            }
        } catch (\Throwable $e) {
            error_log($e->__toString());
        }
    }


}