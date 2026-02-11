<?php

namespace LPagery\data;

use LPagery\factories\InputParamProviderFactory;
use LPagery\factories\SubstitutionHandlerFactory;
use LPagery\service\image_lookup\AttachmentBasenameService;
use LPagery\utils\Utils;

class LPageryDatabaseMigrator
{
    function lpagery_table_exists_migrate(string $table_name_process)
    {

        global $wpdb;
        $dbname = $wpdb->dbname;
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT
                    TABLE_NAME
                FROM
                    information_schema.TABLES
                WHERE
                        TABLE_NAME = %s and TABLE_SCHEMA = %s
            ) as lpagery_table_exists;", $table_name_process, $dbname);
        $process_table_exists = $wpdb->get_results($prepare)[0]->lpagery_table_exists;
        return $process_table_exists;
    }


    function migrate()
    {
        global $wpdb;

        // Short-circuit if already at latest version (15)
        $db_version = intval(get_option("lpagery_database_version", 0));
        if ($db_version >= 15) {
            return;
        }

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $table_name_app_tokens = $wpdb->prefix . 'lpagery_app_tokens';


        $process_table_exists = $this->lpagery_table_exists_migrate($table_name_process);

        $process_post_table_exists = $this->lpagery_table_exists_migrate($table_name_process_post);
        $sync_queue_table_exists = $this->lpagery_table_exists_migrate($table_name_sync_queue);
        $app_tokens_table_exists = $this->lpagery_table_exists_migrate($table_name_app_tokens);
        $charset_collate = '';
        if (!empty($wpdb->charset)) {
            $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
        }
        if (!empty($wpdb->collate)) {
            $charset_collate .= " COLLATE $wpdb->collate";
        }

        $sql_process = "CREATE TABLE {$table_name_process} (
                id      bigint auto_increment     not null ,
			    post_id bigint   not null,
			    user_id bigint   not null,
			    purpose text, 
			    created timestamp,
			    data  longtext,
			    primary key  (id),
			     key  process_post_id(post_id) ,
			     key  process_user_id(user_id) 
            ) $charset_collate";


        $sql_process_post = "CREATE TABLE  {$table_name_process_post} (
                id bigint  auto_increment not null,
			    lpagery_post_id bigint not null,
			    lpagery_process_id bigint not null,
			    post_id            bigint not null,
			    created            timestamp,
			    modified           timestamp ,
			    data  longtext,
			    primary key  (id),
			     key  process_post_lpagery_process_id(lpagery_process_id) ,
			     key  process_post_post_id(post_id),
			    key process_post_lpagery_post_id(lpagery_post_id)
            ) $charset_collate";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');


        if (!$process_table_exists) {
            $wpdb->query($sql_process);
        }
        if (!$process_post_table_exists) {
            $wpdb->query($sql_process_post);
        }

        if(!$sync_queue_table_exists) {
            $sql_sync_queue = "create table $table_name_sync_queue
                (
                    id         bigint auto_increment  primary key,
                    process_id bigint   not null,
                    data       longtext not null,
                    creation_id       text not null,
                    slug       text not null,
                    retry       int not null default 0,
                    created       timestamp not null,
                    error       text,
                    key sync_queue_process_id (process_id)
                )
                    $charset_collate
                
                
                ";
            $wpdb->query($sql_sync_queue);
        }

        $db_version = intval(get_option("lpagery_database_version", 0));
        if ($db_version < 2 || !$process_post_table_exists) {

            try {
                $wpdb->query("alter table $table_name_process_post add column  replaced_slug text");
            } catch (\Throwable $e) {

            }


            $dataResults = $wpdb->get_results("select id, data from $table_name_process p");
            foreach ($dataResults as $result) {
                if (!$result->data) {
                    continue;
                }
                $unserialized_data = maybe_unserialize($result->data);
                if (!$unserialized_data) {
                    continue;
                }

                $slug = isset($unserialized_data["slug"]) ? ($unserialized_data["slug"]) : null;
                if (!$slug) {
                    continue;
                }
                $slug = Utils::lpagery_sanitize_title_with_dashes($slug);
                $process_id = $result->id;

                $process_post_results = $wpdb->get_results($wpdb->prepare("select id,data FROM $table_name_process_post where lpagery_process_id = %s ",
                    $process_id));
                foreach ($process_post_results as $process_post_result) {
                    $process_post_data = maybe_unserialize($process_post_result->data);
                    if (!$process_post_result->data) {
                        continue;
                    }
                    if (!$process_post_data) {
                        continue;
                    }
                    $params = InputParamProviderFactory::create()->lpagery_get_input_params_without_images($process_post_data);
                    $replaced_slug = sanitize_title(SubstitutionHandlerFactory::create()->lpagery_substitute($params,
                        $slug));
                    $wpdb->query($wpdb->prepare("update $table_name_process_post set replaced_slug = %s where id = %s and replaced_slug is null",
                        $replaced_slug, $process_post_result->id));
                }

            }

            try {
                $sql = "alter table $table_name_process_post drop column lpagery_post_id;";
                $wpdb->query($sql);
                $wpdb->query("alter table $table_name_process 
        add column  google_sheet_data longtext,
        add column  google_sheet_sync_status text,
        add column  google_sheet_sync_error longtext,
        add column  google_sheet_sync_enabled boolean,
        add column  last_google_sheet_sync timestamp,
        add column  config_changed boolean");
            } catch (\Throwable $e) {

            }
            $table_exists = $this->lpagery_table_exists_migrate($table_name_process);
            if ($table_exists) {
                update_option("lpagery_database_version", 2);
            }

        }
        $db_version = intval(get_option("lpagery_database_version", 0));

        if ($db_version < 3 && $this->lpagery_table_exists_migrate($table_name_process)) {
            $wpdb->query("alter table $table_name_process_post add column config text");
            $wpdb->query("alter table $table_name_process_post add column lpagery_settings text");
            $wpdb->query("alter table $table_name_process drop column config_changed");
            update_option("lpagery_database_version", 3);

        }

        $db_version = intval(get_option("lpagery_database_version", 0));

        if ($db_version < 4 && $this->lpagery_table_exists_migrate($table_name_process)) {
            $wpdb->query("alter table $table_name_process_post
        modify created timestamp null,
        modify modified timestamp null;");

            $wpdb->query("alter table $table_name_process
        modify created timestamp null");

            $wpdb->query("alter table $table_name_process_post
        add column template_id bigint;");


            $wpdb->query("update $table_name_process_post
                    set template_id = (select post_id from $table_name_process lp where lp.id = $table_name_process_post.lpagery_process_id);");

            $wpdb->query("create index process_post_template on $table_name_process_post (template_id)");
            update_option("lpagery_database_version", 4);
        }
        $wpdb->show_errors();
        if ($db_version < 5 && $this->lpagery_table_exists_migrate($table_name_process)) {
            // Check if the index exists
            $index_exists = $wpdb->get_var("
                    SELECT COUNT(1)
                    FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE table_name = '$table_name_process_post'
                    AND index_name = 'lpagery_uq_lpagery_post_id_process_id'
                ");

            if ($index_exists) {
                // Drop the index if it exists
                $sql = "DROP INDEX lpagery_uq_lpagery_post_id_process_id ON $table_name_process_post;";
                $wpdb->query($sql);
            }

            // Update the database version
            update_option("lpagery_database_version", 5);
        }
        if ($db_version < 6 && $this->lpagery_table_exists_migrate($table_name_process)) {

            $wpdb->query("alter table $table_name_process add column queue_count int not null default 0, add column processed_queue_count int  not null default 0");
            update_option("lpagery_database_version", 6);
        }

        if ($db_version < 7 && $this->lpagery_table_exists_migrate($table_name_process)) {
            $wpdb->query("CREATE INDEX idx_lpagery_post ON $wpdb->posts (post_name, post_type, post_status);");
            $wpdb->query("CREATE INDEX idx_wp_lpagery_process_post ON $table_name_process_post (post_id, lpagery_process_id);");
            update_option("lpagery_database_version", 7);
        }

        if ($db_version < 8 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            $wpdb->query("ALTER TABLE $table_name_process_post ADD COLUMN page_manually_updated_at timestamp null default null");
            $wpdb->query("ALTER TABLE $table_name_process_post ADD COLUMN page_manually_updated_by int");
            update_option("lpagery_database_version", 8);
        }

        if ($db_version < 9 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            $wpdb->query("ALTER TABLE $table_name_sync_queue ADD COLUMN status_from_dashboard varchar(255) not null default '-1'");
            $wpdb->query("ALTER TABLE $table_name_sync_queue ADD COLUMN publish_timestamp timestamp null default null");
            $wpdb->query("ALTER TABLE $table_name_sync_queue ADD COLUMN force_update boolean not null default false");
            $wpdb->query("ALTER TABLE $table_name_sync_queue ADD COLUMN overwrite_manual_changes boolean not null default false");
            update_option("lpagery_database_version", 9);

        }

        if ($db_version < 10 && $this->lpagery_table_exists_migrate($table_name_process_post)) {

            $wpdb->query("CREATE INDEX idx_lpagery_post_type_status ON $wpdb->posts ( post_type, post_status);");
            update_option("lpagery_database_version", 10);

        }

        if ($db_version < 11 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            $table_name_image_search_cache = $wpdb->prefix . 'lpagery_image_search_result_cache';

            $sql_image_search_cache = "CREATE TABLE {$table_name_image_search_cache} (
                id            bigint auto_increment primary key,
                search_term   text   not null,
                attachment_id bigint not null,
                file_name     text not null,
                image_found boolean not null
            ) $charset_collate";
            
            $wpdb->query($sql_image_search_cache);
            $wpdb->query("CREATE INDEX index_image_search_result_search_term ON {$table_name_image_search_cache} (search_term(191))");
            
            update_option("lpagery_database_version", 11);
        }
        if ($db_version < 12 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            $wpdb->query("ALTER TABLE $table_name_sync_queue  add column  existing_page_update_action varchar(100) not null default 'create' ");
            $wpdb->query("ALTER TABLE $table_name_sync_queue  add column  parent_id int not null default 0;");
            $wpdb->query("ALTER TABLE $table_name_process_post  add column parent_search_term text ");
            $wpdb->query("ALTER TABLE $table_name_process  add column include_parent_as_identifier boolean not null default false ");
            $wpdb->query("ALTER TABLE $table_name_process  add column existing_page_update_action varchar(100) not null default 'create' ");
            update_option("lpagery_database_version", 12);
        }

        if ($db_version < 13 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            $sql_app_tokens = "CREATE TABLE {$table_name_app_tokens} (
                id bigint auto_increment primary key,
                user_id bigint not null,
                created_at timestamp not null default current_timestamp,
                last_used_at timestamp null,
                app_user_mail_address varchar(255) not null,
                token varchar(191) not null,
                KEY user_id_index (user_id),
                UNIQUE KEY token_unique (token)
            ) $charset_collate";

            $wpdb->query("ALTER TABLE $table_name_process ADD COLUMN managing_system varchar(255) not null default 'plugin'");
            $wpdb->query($sql_app_tokens);

            $wpdb->query("ALTER TABLE $table_name_process_post add column client_generated_slug text");
            update_option("lpagery_database_version", 13);
        }
        if ($db_version < 14 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            $wpdb->query("ALTER TABLE $table_name_process_post add column hashed_payload varchar(255)");
            $wpdb->query("ALTER TABLE $table_name_sync_queue add column hashed_payload varchar(255) ");
            $wpdb->query("create index process_post_hashed_payload_process_id on $table_name_process_post (hashed_payload, lpagery_process_id)");
            update_option("lpagery_database_version", 14);
        }

        if ($db_version < 15 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            $table_name_attachment_basename = $wpdb->prefix . 'lpagery_attachment_basename';

            $sql_attachment_basename = "CREATE TABLE {$table_name_attachment_basename} (
                attachment_id BIGINT UNSIGNED NOT NULL,
                basename VARCHAR(191) NOT NULL,
                basename_no_ext VARCHAR(191) NOT NULL,
                KEY idx_basename (basename),
                KEY idx_basename_no_ext (basename_no_ext),
                PRIMARY KEY (attachment_id)
            ) $charset_collate";

            $wpdb->query($sql_attachment_basename);

            // Backfill from existing attachments
            AttachmentBasenameService::get_instance()->backfill();

            update_option("lpagery_database_version", 15);
        }
    }
}