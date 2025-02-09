<?php

namespace LPagery\service\delete;

class ResetLPageryService {
    private DeleteProcessService $deleteProcessService;
    public static $instance;

    public static function getInstance(DeleteProcessService $deleteProcessService) {
        if (self::$instance === null) {
            self::$instance = new self($deleteProcessService);
        }
        return self::$instance;
    }

    public function __construct(DeleteProcessService $deleteProcessService) {
        $this->deleteProcessService = $deleteProcessService;
    }
    

    public function resetLPagery(bool $delete_posts) {
        global $wpdb;

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $table_name_queue = $wpdb->prefix . 'lpagery_queue';
        $table_name_image_cache = $wpdb->prefix . 'lpagery_image_search_result_cache';
        $all_process_ids = $wpdb->get_col("SELECT id FROM $table_name_process");

        foreach ($all_process_ids as $process_id) {
            $this->deleteProcessService->deleteProcess($process_id, $delete_posts);
        }


        delete_option('lpagery_database_version');
        $wpdb->query("DROP TABLE IF EXISTS $table_name_process");
        $wpdb->query("DROP TABLE IF EXISTS $table_name_process_post");
        $wpdb->query("DROP TABLE IF EXISTS $table_name_queue");
        $wpdb->query("DROP TABLE IF EXISTS $table_name_image_cache");
    }
}
