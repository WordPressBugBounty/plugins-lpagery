<?php

namespace LPagery\service\sheet_sync;

use Exception;
use LPagery\service\save_page\CreatePostDelegate;

class GoogleSheetSyncPostSaveDelegate
{

    private static $instance;
    private CreatePostDelegate $createPostDelegate;

    public function __construct(CreatePostDelegate $createPostDelegate)
    {
        $this->createPostDelegate = $createPostDelegate;
    }

    public static function get_instance(CreatePostDelegate $createPostDelegate)
    {
        if (null === self::$instance) {
            self::$instance = new self($createPostDelegate);
        }
        return self::$instance;
    }


    public function createViaRest($creation_id, $data, $process_id)
    {
        $ajaxData = array("creation_id" => $creation_id,
            "data" => ($data),
            "process_id" => $process_id,
            "nonce" => wp_create_nonce('lpagery_create_post'),);
        $url = rest_url('lpagery/v1/create_posts');
        $response = wp_remote_post($url, array('method' => 'POST',
            'body' => $ajaxData,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8')));

        $response_body = wp_remote_retrieve_body($response);
        if($response_body) {
            $result = json_decode($response_body, true);
        } else {
            throw new Exception("Failed to create Post " . json_encode($response));
        }

        if (!array_key_exists("success", $result) || !$result["success"]) {
            throw new Exception("Failed to create Post " . json_encode($response));
        }

        return $result["slug"];
    }

    public function createViaFunction($data, $process, $processed_slugs)
    {
        $google_sheet_data = $process["google_sheet_data"];
        $post_param = array("process_id" => $process["id"],
            "data" => $data);
        $operations = array();

        if ($google_sheet_data["add"]) {
            $operations[] = "create";
        }

        if ($google_sheet_data["update"]) {
            $operations[] = "update";
        }

        $result = $this->createPostDelegate->lpagery_create_post($post_param, $processed_slugs, $operations);
        return  $result["replaced_slug"];

    }
}