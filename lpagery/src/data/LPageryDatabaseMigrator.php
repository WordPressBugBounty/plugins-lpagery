<?php

namespace LPagery\data;

use LPagery\factories\InputParamProviderFactory;
use LPagery\factories\SubstitutionHandlerFactory;
use LPagery\utils\Utils;
use Throwable;

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

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';


        $process_table_exists = $this->lpagery_table_exists_migrate($table_name_process);

        $process_post_table_exists = $this->lpagery_table_exists_migrate($table_name_process_post);
        $sync_queue_table_exists = $this->lpagery_table_exists_migrate($table_name_sync_queue);
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
			     key  post_id(post_id) ,
			     key  user_id(user_id) 
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
			     key  lpagery_process_id(lpagery_process_id) ,
			     key  post_id(post_id),
			    key lpagery_post_id(lpagery_post_id)
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
                    key process_id (process_id)
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

    }
}