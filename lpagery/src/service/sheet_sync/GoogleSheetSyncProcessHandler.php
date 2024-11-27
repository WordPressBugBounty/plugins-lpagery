<?php

namespace LPagery\service\sheet_sync;

use Exception;
use LPagery\data\LPageryDao;
use LPagery\io\Api;
use LPagery\utils\MemoryUtils;
use Throwable;

class GoogleSheetSyncProcessHandler
{
    private static $instance;
    private Api $api;
    private LPageryDao $lpageryDao;
    private GoogleSheetSyncPostSaveDelegate $postSaveDelegate;
    private GoogleSheetSyncPostDeleteHandler $postDeleteHandler;

    public function __construct(Api $api, LPageryDao $LPageryDao, GoogleSheetSyncPostSaveDelegate $postSaveDelegate, GoogleSheetSyncPostDeleteHandler $postDeleteHandler)
    {
        $this->api = $api;
        $this->lpageryDao = $LPageryDao;
        $this->postSaveDelegate = $postSaveDelegate;
        $this->postDeleteHandler = $postDeleteHandler;
    }


    public static function get_instance(Api $api, LPageryDao $LPageryDao, GoogleSheetSyncPostSaveDelegate $postSaveDelegate, GoogleSheetSyncPostDeleteHandler $postDeleteHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($api, $LPageryDao, $postSaveDelegate, $postDeleteHandler);
        }
        return self::$instance;
    }


    public function handleProcess(array $process, string $sheet_sync_type)
    {
        set_time_limit(120);
        $this->lpageryDao->lpagery_update_process_sync_status($process["id"], "RUNNING");
        $count_transient_name = 'lpagery_sync_count_' . $process["id"];
        $creation_id = $this->generate_creation_id();
        $transient_key = "lpagery_$creation_id";

        try {
            $google_sheet_data = $process["google_sheet_data"];
            $response = $this->api->lpagery_get_google_doc(array("doc_url" => urlencode($google_sheet_data["url"])));
            $encoded_json = json_decode($response, true);


            if (empty($encoded_json) || !array_key_exists("data", $encoded_json)) {
                throw new Exception("Failed to Fetch Google Sheet " . $response);
            }

            $input_data = $encoded_json["data"];
            $this->processData($input_data, $process, $count_transient_name, $creation_id, $sheet_sync_type);

            if ($google_sheet_data["delete"] ?? false) {
                $this->postDeleteHandler->handleDeletions($input_data, $process);
            }

            $this->lpageryDao->lpagery_update_process_sync_status($process["id"], "FINISHED");

        } catch (Throwable $e) {
            error_log($e->__toString());
            $this->lpageryDao->lpagery_update_process_sync_status($process["id"], "ERROR", $e->__toString());
        } finally {
            delete_transient($count_transient_name);
            delete_transient($transient_key);
        }
    }

    /**
     * @throws \Exception
     */
    private function processData($input_data, $process, $count_transient_name, $creation_id, $sheet_sync_type)
    {
        $count = 0;
        $processed_slugs = array();
        foreach ($input_data as $response_entry) {
            $count++;
            if ($count % 100 == 0) {
                error_log("Sleeping for 10 seconds");
                sleep(10);
            } else if ($count % 50 == 0) {
                error_log("Sleeping for 5 seconds");
                sleep(5);

            }
            set_transient("lpagery_sync_running", true, 30);
            set_time_limit(30);
            MemoryUtils::updateSheetSyncRamData();

            try {
                if ($sheet_sync_type == 'single_process') {
                    $slug = $this->postSaveDelegate->createViaFunction($response_entry, $process, $processed_slugs);
                    $processed_slugs[] = $slug;
                } else {
                    $this->postSaveDelegate->createViaRest($creation_id, $response_entry, $process["id"]);

                }
            } catch (Throwable $e) {
                throw new Exception("Error at generating page $count " . $e->getMessage());
            }

            set_transient($count_transient_name, array("current" => $count,
                "total" => count($input_data)), 30);
        }
    }

    /**
     * @return string
     */
    public function generate_creation_id(): string
    {
        return uniqid();
    }


}