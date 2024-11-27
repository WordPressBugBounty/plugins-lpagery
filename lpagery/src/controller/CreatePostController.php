<?php

namespace LPagery\controller;

use LPagery\data\LPageryDao;
use LPagery\service\save_page\CreatePostDelegate;
use LPagery\utils\MemoryUtils;
use Throwable;
use WP_Error;
use WP_REST_Request;

if(!defined('TEST_RUNNING')){
    include_once(plugin_dir_path(__FILE__) . '/../utils/IncludeWordpressFiles.php');
}
class CreatePostController
{

    private static $instance;
    private CreatePostDelegate $createPostDelegate;
    private LPageryDao $LPageryDao;

    public function __construct(CreatePostDelegate $createPostDelegate, LPageryDao $LPageryDao)
    {
        $this->createPostDelegate = $createPostDelegate;
        $this->LPageryDao = $LPageryDao;

    }

    public static function get_instance(CreatePostDelegate $createPostDelegate, LPageryDao $LPageryDao)
    {
        if (null === self::$instance) {
            self::$instance = new self($createPostDelegate, $LPageryDao);
        }
        return self::$instance;
    }

    public function lpagery_create_posts_rest(WP_REST_Request $request)
    {
        $nonce = $request->get_param('nonce');
        if (!wp_verify_nonce($nonce, 'lpagery_create_post')) {
            return new WP_Error('invalid_nonce', 'Invalid nonce', array('status' => 403));
        }
        $params = $request->get_params();

        $creation_id = $params["creation_id"] ?? null;
        $transient_key = "lpagery_$creation_id";
        $processed_slugs = get_transient($transient_key);
        if (!$processed_slugs) {
            $processed_slugs = [];
        } else {
            $processed_slugs = maybe_unserialize($processed_slugs);
        }
        $process_id = (int)($params['process_id'] ?? 0);
        $process = $this->LPageryDao->lpagery_get_process_by_id($process_id);
        $google_sheet_data = maybe_unserialize($process->google_sheet_data);
        $operations = array();
        if ($google_sheet_data["add"]) {
            $operations[] = "create";
        }
        if ($google_sheet_data["update"]) {
            $operations[] = "update";
        }
        $response = $this->createPostDelegate->lpagery_create_post($params, $processed_slugs, $operations);
        if ($creation_id && $response["replaced_slug"] && $response["mode"] !== "ignored") {
            $processed_slugs[] = $response["replaced_slug"];
            set_transient($transient_key, $processed_slugs, 60);
        }

        $replaced_slug = $this->getReplaced_slug($response);

        $result_array = array("success" => true,
            "slug" => $replaced_slug);

        return ($result_array);
    }


    function lpagery_create_posts_ajax($post_data)
    {
        $nonce_validity = check_ajax_referer('lpagery_ajax');
        $creation_id = $post_data["creation_id"];
        $transient_key = "lpagery_$creation_id";
        $is_last_page = filter_var($post_data["is_last_page"], FILTER_VALIDATE_BOOLEAN);

        $processed_slugs = get_transient($transient_key);
        if (!$processed_slugs) {
            $processed_slugs = [];
        } else {
            $processed_slugs = maybe_unserialize($processed_slugs);
        }

        $response = $this->createPostDelegate->lpagery_create_post($post_data, $processed_slugs);
        if ($creation_id && $response["replaced_slug"]) {
            $processed_slugs[] = $response["replaced_slug"];
            if (!$is_last_page) {
                set_transient($transient_key, $processed_slugs, 60);
            }
        }

        $memory_usage = $this->getMemory_usage();
        $mode = $this->getMode($response);
        $replaced_slug = $this->getReplaced_slug($response);
        $result_array = array("success" => true,
            "mode" => $mode,
            "used_memory" => $memory_usage,
            "slug" => $replaced_slug);

        $result_array = $this->append_new_nonce_if_needed($nonce_validity, $result_array);
        $this->set_finished_if_last_page($post_data, $is_last_page);

        if($is_last_page) {
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
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }
        return $memory_usage;
    }

    /**
     * @param array $response
     * @return mixed|string
     */
    private function getMode(array $response)
    {
        if (array_key_exists("mode", $response)) {
            $mode = $response["mode"];
        } else {
            error_log("Mode not found in response");
            $mode = "ignored";
        }
        return $mode;
    }

    /**
     * @param array $response
     * @return mixed|string
     */
    private function getReplaced_slug(array $response)
    {
        $replaced_slug = "";
        if (array_key_exists("replaced_slug", $response)) {
            $replaced_slug = $response["replaced_slug"];
        }
        return $replaced_slug;
    }

    /**
     * @param $nonce_validity
     * @param array $result_array
     * @return array
     */
    public function append_new_nonce_if_needed($nonce_validity, array $result_array): array
    {
        if ($nonce_validity == 2) {
            $result_array["new_nonce"] = wp_create_nonce("lpagery_ajax");
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
        } catch (Throwable $e) {
            error_log($e->__toString());
        }
    }




}