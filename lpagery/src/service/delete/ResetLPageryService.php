<?php

namespace LPagery\service\delete;

/**
 * Reset LPagery: drop every Page Set, LPagery's own tables and its stored database version, and
 * optionally every page LPagery generated. The reset states a full wipe as its intent, so it forces
 * the page deletion past the live template guard (ADR 0017). Honouring the guard here would keep a
 * Template Page of a chained live set alive while the live pages that need it are already gone, and
 * which pages survived would depend on the order the Page Sets happen to be deleted in.
 */
class ResetLPageryService {
    private DeleteProcessService $deleteProcessService;

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
            $this->deleteProcessService->deleteProcess((int)$process_id, $delete_posts, true);
        }


        delete_option('lpagery_database_version');
        $wpdb->query("DROP TABLE IF EXISTS $table_name_process");
        $wpdb->query("DROP TABLE IF EXISTS $table_name_process_post");
        $wpdb->query("DROP TABLE IF EXISTS $table_name_queue");
        $wpdb->query("DROP TABLE IF EXISTS $table_name_image_cache");
    }
}
