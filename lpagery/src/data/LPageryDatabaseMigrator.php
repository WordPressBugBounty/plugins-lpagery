<?php

namespace LPagery\data;

use LPagery\service\image_lookup\AttachmentBasenameService;
use LPagery\service\InstallationDateHandler;
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

    function lpagery_column_exists_migrate(string $table_name, string $column_name)
    {
        global $wpdb;
        $dbname = $wpdb->dbname;
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT
                    COLUMN_NAME
                FROM
                    information_schema.COLUMNS
                WHERE
                        TABLE_NAME = %s and COLUMN_NAME = %s and TABLE_SCHEMA = %s
            ) as lpagery_column_exists;", $table_name, $column_name, $dbname);
        return $wpdb->get_results($prepare)[0]->lpagery_column_exists;
    }

    /**
     * Whether $index_name exists on $table_name in THIS site's schema. Mirrors
     * {@see lpagery_column_exists_migrate()} — filtered by TABLE_SCHEMA, so a same-named table in
     * another database on the same server cannot answer for this one (the v5 step's unfiltered
     * STATISTICS probe is the counter-example this avoids).
     */
    private function lpagery_index_exists_migrate(string $table_name, string $index_name): bool
    {
        global $wpdb;
        $index_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(1)
                FROM information_schema.STATISTICS
                WHERE TABLE_NAME = %s AND INDEX_NAME = %s AND TABLE_SCHEMA = %s", $table_name,
            $index_name, $wpdb->dbname));
        return intval($index_count) > 0;
    }


    /**
     * Stamps the plugin's installation date into the `lpagery_installation_date` option unless it is
     * already there, and reports whether the option holds a date afterwards (issue #276).
     *
     * For an install that predates the option the date is the `lpagery_process` table's create time,
     * read from information_schema ONCE here instead of on every admin request; a fresh install (no
     * table yet) is stamped with the current time. Reading the existing option first makes the call
     * cost a single autoloaded get_option() on a normal admin load.
     */
    public function record_installation_date_if_missing(): bool
    {
        global $wpdb;

        $existing_date = get_option(InstallationDateHandler::OPTION_NAME, false);
        if (is_string($existing_date) && trim($existing_date) !== '') {
            return true;
        }

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $installation_date = '';
        if ($this->lpagery_table_exists_migrate($table_name_process)) {
            $create_time = $wpdb->get_var($wpdb->prepare("SELECT CREATE_TIME
                FROM information_schema.TABLES
                WHERE TABLE_NAME = %s AND TABLE_SCHEMA = %s", $table_name_process, $wpdb->dbname));
            if (is_string($create_time) && trim($create_time) !== '') {
                $installation_date = $create_time;
            }
        }
        if ($installation_date === '') {
            $installation_date = current_time('mysql');
        }

        update_option(InstallationDateHandler::OPTION_NAME, $installation_date);

        $stored_date = get_option(InstallationDateHandler::OPTION_NAME, false);
        return is_string($stored_date) && trim($stored_date) !== '';
    }

    function migrate()
    {
        global $wpdb;

        // Re-record the installation date whenever it is missing — ahead of the version short-circuit,
        // so a site restored from a backup without the option gets it back on the next admin load
        // rather than staying on the fallback answers forever. Costs one autoloaded get_option() once
        // the option is there.
        $this->record_installation_date_if_missing();

        // Short-circuit if already at latest version (23).
        // NOTE: the installed version can be ahead of this constant — the Views feature
        // shipped v16 (lpagery_view) and v17 (lpagery_process_post_meta) to some installs.
        // A step numbered <= the stored version is silently skipped; Live Mode's column shipped
        // as v18, then the queue `operation` column (v19) and the `background_run_document` blob
        // (v20); the per-page `spin_seed` shipped as v21, the installation date option as v22, and
        // the latest step adds the (template_id, modified) index behind the Template Page
        // pending-changes check (v23), raising the ceiling to 23.
        $db_version = intval(get_option("lpagery_database_version", 0));
        if ($db_version >= 23) {
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
                    $params = lpagery_root()->inputParamProvider()->lpagery_get_input_params_without_images($process_post_data);
                    $replaced_slug = sanitize_title(lpagery_root()->substitutionHandler()->lpagery_substitute($params,
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

            // Backfill from existing attachments. Constructed directly rather than via the
            // composition root: the migrator runs during schema setup (below the wiring layer,
            // where lpagery_root() is not guaranteed) and this is a zero-dependency leaf.
            (new AttachmentBasenameService())->backfill();

            update_option("lpagery_database_version", 15);
        }

        // v16: Views storage table (related-pages display Views). One row per saved View,
        // each belonging to exactly one Process. See ADR-0003.
        if ($db_version < 16 && $this->lpagery_table_exists_migrate($table_name_process)) {
            $table_name_view = $wpdb->prefix . 'lpagery_view';

            $sql_view = "CREATE TABLE {$table_name_view} (
                id bigint auto_increment primary key,
                process_id bigint not null,
                name varchar(191) not null default '',
                match_key varchar(191) not null,
                mode varchar(50) not null default 'list',
                config longtext,
                created timestamp null,
                modified timestamp null,
                KEY idx_view_process_id (process_id)
            ) $charset_collate";

            // Create the table only if it isn't already there, then advance the version once it
            // exists. Treating an already-existing table as success (a prior run may have created it
            // but failed to bump the version) keeps a plain CREATE TABLE returning false on an
            // existing table from blocking retries forever; only a genuinely absent table leaves the
            // version unchanged so the next load retries. Track the bump locally too: the sequential
            // v17 step below must not run (and skip v16 forever) while this step is pending.
            $view_table_exists = (bool)$this->lpagery_table_exists_migrate($table_name_view);
            if (!$view_table_exists) {
                $wpdb->query($sql_view);
                $view_table_exists = (bool)$this->lpagery_table_exists_migrate($table_name_view);
            }
            if ($view_table_exists) {
                update_option("lpagery_database_version", 16);
                $db_version = 16;
            }
        }

        // v17: Sparse meta index for View matching. Mirrors selected per-page placeholder
        // values (only Indexed keys — keys used as some View's match key) out of the
        // serialized wp_lpagery_process_post.data, so value-based matching is an indexed
        // query rather than a 100k-blob deserialize. See ADR-0001.
        // Gated on $db_version >= 16 so a failed v16 (above) blocks v17 from advancing the version
        // past the missing lpagery_view table; the retry on the next load runs v16 first.
        if ($db_version >= 16 && $db_version < 17 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            $table_name_process_post_meta = $wpdb->prefix . 'lpagery_process_post_meta';

            // One row per (post_id, meta_key): lpagery_upsert_post_meta does delete-then-insert and
            // the resolver reads rows without DISTINCT, so a UNIQUE key here keeps overlapping
            // backfill/write syncs from ever creating duplicate rows (and duplicate related pages).
            // The composite (process_id, meta_key, meta_value, post_id) is a covering index for the
            // resolver lookup lpagery_get_post_ids_by_meta() — WHERE process_id+meta_key+meta_value
            // ORDER BY post_id, SELECT post_id — so it resolves without a filesort or row lookup and
            // its prefix also serves process_id/meta_key scans (subsuming the older split indexes).
            $sql_process_post_meta = "CREATE TABLE {$table_name_process_post_meta} (
                id bigint auto_increment primary key,
                post_id bigint not null,
                process_id bigint not null,
                meta_key varchar(191) not null,
                meta_value varchar(191) not null,
                KEY idx_meta_process_key_value_post (process_id, meta_key, meta_value, post_id),
                UNIQUE KEY uq_meta_post_key (post_id, meta_key),
                KEY idx_meta_post (post_id)
            ) $charset_collate";

            // Create the table only if absent, then advance the version once it exists (an
            // already-existing table counts as success), so a CREATE TABLE returning false on a
            // pre-existing table can't block retries; only a genuinely absent table holds the version.
            $meta_table_exists = (bool)$this->lpagery_table_exists_migrate($table_name_process_post_meta);
            if (!$meta_table_exists) {
                $wpdb->query($sql_process_post_meta);
                $meta_table_exists = (bool)$this->lpagery_table_exists_migrate($table_name_process_post_meta);
            }
            if ($meta_table_exists) {
                update_option("lpagery_database_version", 17);
                $db_version = 17;
            }
        }

        // v18: Live Mode — attachment_id_pairs column on process_post. Gated on
        // $db_version >= 17 so a failed v16/v17 (above) can't advance the version to 18
        // and short-circuit the retry of the Views tables on the next load.
        if ($db_version >= 17 && $db_version < 18 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            if (!$this->lpagery_column_exists_migrate($table_name_process_post, "attachment_id_pairs")) {
                $wpdb->query("ALTER TABLE $table_name_process_post add column attachment_id_pairs longtext");
            }
            update_option("lpagery_database_version", 18);
            $db_version = 18;
        }

        // v19: Background Generation — the `operation` discriminator on the queue (issue #220 Phase 3).
        // Generalizes lpagery_sync_queue from a sheet-sync-only queue into the one background-job queue:
        // every row carries the operation the worker dispatches on. Existing rows and the sheet-sync
        // producer default to 'sheet_sync', so sync behaviour is unchanged. Gated on $db_version >= 18
        // so a failed earlier step can't skip it, and the column add is guarded for idempotency.
        if ($db_version >= 18 && $db_version < 19 && $this->lpagery_table_exists_migrate($table_name_sync_queue)) {
            if (!$this->lpagery_column_exists_migrate($table_name_sync_queue, "operation")) {
                $wpdb->query("ALTER TABLE $table_name_sync_queue add column operation varchar(50) not null default 'sheet_sync'");
            }
            update_option("lpagery_database_version", 19);
            $db_version = 19;
        }

        // v20: Background Generation — the run-record document blob on the Page Set (issue #220 Phase 4).
        // A background file-upload run stores its parsed Row Data document as a LONGTEXT blob on
        // lpagery_process; the enqueue pipeline expands it into queue items and clears the blob, so no
        // long-lived intermediary storage remains. Gated on $db_version >= 19 so a failed earlier step
        // can't skip it, and the column add is guarded for idempotency.
        if ($db_version >= 19 && $db_version < 20 && $this->lpagery_table_exists_migrate($table_name_process)) {
            if (!$this->lpagery_column_exists_migrate($table_name_process, "background_run_document")) {
                $wpdb->query("ALTER TABLE $table_name_process add column background_run_document longtext");
            }
            update_option("lpagery_database_version", 20);
            $db_version = 20;
        }

        // v21: Spintax — the per-page Spin Seed on process_post (issue #244, ADR 0017). Each Generated
        // Page freezes its spintax picks by resolving them from a seed stored once at creation, so
        // template edits, Google Sheet re-syncs and forced updates no longer reshuffle its wording.
        // Existing rows predate the seed, so the same step backfills every one of them with its own
        // random value (RAND() is evaluated per row) — a page that has been generated already keeps
        // whatever wording it lands on from here, instead of re-rolling forever. Gated on
        // $db_version >= 20 so a failed earlier step can't skip it; the column add and the backfill
        // are both guarded, so a retry is a no-op.
        if ($db_version >= 20 && $db_version < 21 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            if (!$this->lpagery_column_exists_migrate($table_name_process_post, "spin_seed")) {
                $wpdb->query("ALTER TABLE $table_name_process_post add column spin_seed int null");
            }
            if ($this->lpagery_column_exists_migrate($table_name_process_post, "spin_seed")) {
                // Advance only when the backfill ran; a failed UPDATE leaves the version at 20 so the
                // next migrate() retries instead of leaving rows with a NULL seed forever.
                $backfilled = $wpdb->query("UPDATE $table_name_process_post SET spin_seed = FLOOR(1 + RAND() * 2147483646) WHERE spin_seed IS NULL");
                if ($backfilled !== false) {
                    update_option("lpagery_database_version", 21);
                    $db_version = 21;
                }
            }
        }

        // v22: the installation date option (issue #276). The three install-age decisions in
        // InstallationDateHandler used to derive the date from an information_schema query on every
        // admin request; from here they read this option instead. Existing installs inherit the
        // lpagery_process table's create time, fresh installs the current time. Gated on
        // $db_version >= 21 so a failed earlier step can't skip it, and the version advances only
        // once the option actually holds a date, so a failed write is retried on the next load.
        if ($db_version >= 21 && $db_version < 22) {
            if ($this->record_installation_date_if_missing()) {
                update_option("lpagery_database_version", 22);
                $db_version = 22;
            }
        }

        // v23: the (template_id, modified) index behind the Template Page pending-changes check
        // (issue #278). That check now reads the template's post_modified once and stops at the
        // first Generated Page older than it; this composite index is what turns the range
        // condition into an index lookup instead of a scan over the whole Page Set. The existing
        // single-column process_post_template index stays — dropping it is a separate decision.
        // Gated on $db_version >= 22 so a failed earlier step can't skip it; the existence check
        // makes a re-run a no-op and the version advances only once the index is really there, so
        // a failed CREATE is retried on the next admin load.
        if ($db_version >= 22 && $db_version < 23 && $this->lpagery_table_exists_migrate($table_name_process_post)) {
            if (!$this->lpagery_index_exists_migrate($table_name_process_post, "process_post_template_modified")) {
                $wpdb->query("CREATE INDEX process_post_template_modified ON $table_name_process_post (template_id, modified)");
            }
            if ($this->lpagery_index_exists_migrate($table_name_process_post, "process_post_template_modified")) {
                update_option("lpagery_database_version", 23);
                $db_version = 23;
            }
        }
    }
}