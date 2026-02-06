<?php
namespace LPagery\data;
class DbDeltaExecutor
{

    public function run(): ?string
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        $queries = [
            "CREATE TABLE {$prefix}lpagery_app_tokens (
                id BIGINT AUTO_INCREMENT,
                user_id BIGINT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
                last_used_at TIMESTAMP NULL,
                app_user_mail_address VARCHAR(255) NOT NULL,
                token VARCHAR(191) NOT NULL,
                UNIQUE KEY token_unique (token),
                KEY user_id_index (user_id),
                PRIMARY KEY (id)
            ) $charset_collate;",

            "CREATE TABLE {$prefix}lpagery_image_search_result_cache (
                id BIGINT AUTO_INCREMENT,
                search_term TEXT NOT NULL,
                attachment_id BIGINT NOT NULL,
                file_name TEXT NOT NULL,
                image_found TINYINT(1) NOT NULL,
                KEY index_image_search_result_search_term (search_term(191)),
                PRIMARY KEY (id)
            ) $charset_collate;",

            "CREATE TABLE {$prefix}lpagery_process (
                id BIGINT AUTO_INCREMENT,
                post_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                purpose TEXT NULL,
                created TIMESTAMP NULL,
                data LONGTEXT NULL,
                google_sheet_data LONGTEXT NULL,
                google_sheet_sync_status TEXT NULL,
                google_sheet_sync_error LONGTEXT NULL,
                google_sheet_sync_enabled TINYINT(1) NULL,
                last_google_sheet_sync TIMESTAMP DEFAULT '0000-00-00 00:00:00' NOT NULL,
                queue_count INT DEFAULT 0 NOT NULL,
                processed_queue_count INT DEFAULT 0 NOT NULL,
                include_parent_as_identifier TINYINT(1) DEFAULT 0 NOT NULL,
                existing_page_update_action VARCHAR(100) DEFAULT 'create' NOT NULL,
                managing_system VARCHAR(255) DEFAULT 'plugin' NOT NULL,
                KEY process_post_id (post_id),
                KEY process_user_id (user_id),
                PRIMARY KEY (id)
            ) $charset_collate;",

            "CREATE TABLE {$prefix}lpagery_process_post (
                id BIGINT AUTO_INCREMENT,
                lpagery_process_id BIGINT NOT NULL,
                post_id BIGINT NOT NULL,
                created TIMESTAMP NULL,
                modified TIMESTAMP NULL,
                data LONGTEXT NULL,
                replaced_slug TEXT NULL,
                config TEXT NULL,
                lpagery_settings TEXT NULL,
                template_id BIGINT NULL,
                page_manually_updated_at TIMESTAMP NULL,
                page_manually_updated_by INT NULL,
                parent_search_term TEXT NULL,
                client_generated_slug TEXT NULL,
                hashed_payload           varchar(255)      null,
                KEY idx_wp_lpagery_process_post (post_id, lpagery_process_id),
                KEY process_post_lpagery_process_id (lpagery_process_id),
                KEY process_post_post_id (post_id),
                KEY process_post_template (template_id),
                KEY process_post_hashed_payload_process_id (hashed_payload, lpagery_process_id),
                PRIMARY KEY (id)
            ) $charset_collate;",

            "CREATE TABLE {$prefix}lpagery_sync_queue (
                id BIGINT AUTO_INCREMENT,
                process_id BIGINT NOT NULL,
                data LONGTEXT NOT NULL,
                creation_id TEXT NOT NULL,
                slug TEXT NOT NULL,
                retry INT DEFAULT 0 NOT NULL,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL ON UPDATE CURRENT_TIMESTAMP,
                error TEXT NULL,
                status_from_dashboard VARCHAR(255) DEFAULT '-1' NOT NULL,
                publish_timestamp TIMESTAMP NULL,
                force_update TINYINT(1) DEFAULT 0 NOT NULL,
                overwrite_manual_changes TINYINT(1) DEFAULT 0 NOT NULL,
                existing_page_update_action VARCHAR(100) DEFAULT 'create' NOT NULL,
                parent_id INT DEFAULT 0 NOT NULL,
                hashed_payload           varchar(255)      null,
                KEY sync_queue_process_id (process_id),
                PRIMARY KEY (id)
            ) $charset_collate;",

            "CREATE TABLE {$prefix}lpagery_attachment_basename (
                attachment_id BIGINT UNSIGNED NOT NULL,
                basename VARCHAR(191) NOT NULL,
                basename_no_ext VARCHAR(191) NOT NULL,
                KEY idx_basename (basename),
                KEY idx_basename_no_ext (basename_no_ext),
                PRIMARY KEY (attachment_id)
            ) $charset_collate;"
        ];

        foreach ($queries as $query) {
            dbDelta($query);
        }

        if($wpdb->last_error) {
           return $wpdb->last_error;
        } else {
            return null;
        }
    }
}
