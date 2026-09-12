<?php

namespace LPagery\data\repository;

use Exception;
use LPagery\service\live_render\ObservedRenderMode;

/**
 * Data access for Page Sets (`lpagery_process` — the per-set config row, ADR naming: a "Page Set";
 * `lpagery_process` is the legacy table name). Owns every read/write of that table: the upsert, the
 * source-post / created-post / id lookups, the admin search, the users/template listings, the count
 * and first-date stats, and the template/user/managing-system mutations.
 *
 * The one focused seam for the Page Set SQL, following the #195 {@see ViewRepository} tracer.
 *
 * ## Persist-only upsert (domain logic evicted — Design D)
 *
 * {@see upsert()} PERSISTS ONLY. The slug substitution/validation that used to live inside the
 * update transaction now runs in {@see \LPagery\controller\ProcessController::upsertProcess()} BEFORE
 * this repository is called: the controller computes and validates the per-page `replaced_slug`
 * values (throwing the "Slug … is not valid" exception before any write) and hands the finished list
 * in via the optional `$slug_updates` argument. This repository therefore imports no
 * substitution/factory/`Utils` symbols and can never throw that validation message — an invalid slug
 * is rejected before the transaction opens, so nothing is persisted, exactly as before.
 *
 * ## `table_exists` instance cache (Design C)
 *
 * {@see table_exists()} memoizes its answer in a private nullable instance field. Table existence is
 * monotonic per request — the `lpagery_process_post` table cannot appear and then vanish within a
 * single request — so a plain memoization suffices; unlike a data cache, no write path can invalidate
 * it, so there is deliberately no invalidation hook.
 */
class PageSetRepository
{
    /**
     * Memoized answer of {@see table_exists()}. Null until first computed. Never invalidated: table
     * existence is monotonic per request (see class docblock).
     *
     * @var bool|null
     */
    private $table_exists = null;

    /**
     * Persist a Page Set (insert when `$process_id <= 0`, otherwise update). PERSIST ONLY — the slug
     * substitution/validation lives in the controller now (Design D); this method just writes what it
     * is given.
     *
     * The update path opens the same `START TRANSACTION … COMMIT` as before: it updates the config
     * blob + `include_parent_as_identifier`, and — when the controller passed a non-empty
     * `$slug_updates` list (items `['id' => process_post_id, 'replaced_slug' => string]`) — updates
     * each `lpagery_process_post.replaced_slug` by id inside the transaction. The Google-Sheet and
     * purpose updates plus the trailing `$wpdb->last_error` throw are unchanged.
     *
     * @param array<int, array{id: int|string, replaced_slug: string}>|null $slug_updates
     * @return int the inserted (new) or existing process id
     */
    public function upsert($post_id, $process_id, $purpose, $data, $google_sheet_data, bool $google_sheet_sync_enabled, bool $include_parent_as_identifier, string $managing_system, ?array $slug_updates = null)
    {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        if (($process_id) <= 0) {
            $wpdb->insert($table_name_process, array("post_id" => $post_id,
                "user_id" => $current_user_id,
                "purpose" => $purpose,
                "data" => serialize($data),
                "google_sheet_data" => serialize($google_sheet_data),
                "google_sheet_sync_enabled" => $google_sheet_sync_enabled,
                "google_sheet_sync_status" => $google_sheet_sync_enabled ? "PLANNED" : null,
                "include_parent_as_identifier" => $include_parent_as_identifier,
                'managing_system' => $managing_system,
                "created" => current_time('mysql')));
            if ($wpdb->last_error) {
                throw new Exception("Failed to insert process " . $wpdb->last_error);
            }
            return $wpdb->insert_id;
        } else {
            if ($data) {
                $wpdb->query("START TRANSACTION");
                $wpdb->update($table_name_process, array("data" => serialize($data),
                    "include_parent_as_identifier" => $include_parent_as_identifier), array("id" => $process_id));
                if (!empty($slug_updates)) {
                    foreach ($slug_updates as $slug_update) {
                        $wpdb->update($table_name_process_post, array("replaced_slug" => $slug_update['replaced_slug']),
                            array("id" => $slug_update['id']));
                    }
                }
                $wpdb->query("COMMIT");
            }
            if ($google_sheet_data) {
                $wpdb->update($table_name_process, array("google_sheet_data" => serialize($google_sheet_data),
                    "google_sheet_sync_enabled" => $google_sheet_sync_enabled), array("id" => $process_id));
            }
            if ($purpose) {
                $wpdb->update($table_name_process, array("purpose" => $purpose), array("id" => $process_id));
            }
            if ($wpdb->last_error) {
                throw new Exception("Failed to upsert process " . $wpdb->last_error);
            }
        }

        return $process_id;
    }

    public function get_processes_by_source_post($post_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $prepare = $wpdb->prepare("select id,
			       user_id,
			       post_id,
			       data,
			       created,
    				purpose,
			       " . $this->non_trashed_count_subquery() . " as count
			from $table_name_process lp
			where post_id = %s", $post_id);

        return $wpdb->get_results($prepare);

    }

    public function search_processes($post_id, $user_id, $search, $empty_filter, string $managing_system = null)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $where_clauses = array();
        $params = array();

        if (is_numeric($post_id) && $post_id > 0) {
            $where_clauses[] = "post_id = %d";
            $params[] = $post_id;
        }
        if (is_numeric($user_id) && $user_id > 0) {
            $where_clauses[] = "user_id = %d";
            $params[] = $user_id;
        }
        if ($managing_system) {
            $where_clauses[] = "managing_system = %s";
            $params[] = $managing_system;
        }
        if (!empty($search) && $search != 'undefined') {
            $where_clauses[] = "purpose LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Add empty filter condition
        if ($empty_filter === 'non-empty') {
            $where_clauses[] = $this->non_trashed_count_subquery() . " > 0";
        } elseif ($empty_filter === 'empty') {
            $where_clauses[] = $this->non_trashed_count_subquery() . " = 0";
        }

        if (empty($where_clauses)) {
            $where_query_text = '';
        } else {
            $where_query_text = ' WHERE ' . implode(' AND ', $where_clauses);
        }
        $where_query_text .= ' ORDER BY created desc';

        $query = "SELECT id,
               user_id,
               google_sheet_sync_enabled,
               google_sheet_sync_status,
               last_google_sheet_sync,
               google_sheet_data,
               data,
               managing_system,
               post_id,
               created,
                purpose,
               queue_count,
               processed_queue_count,
                (SELECT COUNT(lq.id) FROM $table_name_queue lq WHERE lq.process_id = lp.id AND error IS NOT NULL) AS errored,
                (SELECT COUNT(lq.id) FROM $table_name_queue lq WHERE lq.process_id = lp.id AND  error IS NULL) AS in_queue,
                " . $this->non_trashed_count_subquery() . " AS count,
                " . $this->render_mode_count_subquery(true) . " AS live_count,
                " . $this->render_mode_count_subquery(false) . " AS classic_count,
                " . $this->template_footprint_subquery() . " AS template_footprint_bytes
            FROM $table_name_process lp $where_query_text";

        // The footprint's LIKE pattern sits in the SELECT list, i.e. ahead of every WHERE
        // placeholder, so it is the first prepared argument and the query is ALWAYS prepared.
        array_unshift($params, $wpdb->esc_like('_lpagery_') . '%');
        $query = $wpdb->prepare($query, ...$params);

        return $wpdb->get_results($query);
    }

    public function get_process_by_id($process_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $prepare = $wpdb->prepare("select id,
       				purpose,
			       user_id,
			       post_id,
			       data,
			       google_sheet_sync_enabled,
			       google_sheet_sync_status,
			       google_sheet_sync_error,
			       last_google_sheet_sync,
			       google_sheet_data,
			       queue_count,
			       processed_queue_count,
			       include_parent_as_identifier,
			       created,
			       managing_system,
			         " . $this->non_trashed_count_subquery() . " as count
			from $table_name_process lp
			where id = %s", $process_id);

        $results = $wpdb->get_results($prepare);
        return empty($results) ? null : $results[0];

    }

    public function get_process_by_created_post_id($created_post_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lp.id,
       				 lp.purpose,
			        lp.user_id,
			       lp.post_id as post_id,
			       lp.data,
			       lp.google_sheet_sync_enabled,
			        lp.google_sheet_sync_status,
			        lp.google_sheet_sync_error,
			        lp.last_google_sheet_sync,
			        lp.google_sheet_data,
			        lp.queue_count,
			        lp.processed_queue_count,
			        lp.created,
			          lp.include_parent_as_identifier,
			         " . $this->non_trashed_count_subquery('lpp_count') . " as count

			from $table_name_process lp
			inner join $table_name_process_post lpp on lpp.lpagery_process_id = lp.id
			where lpp.post_id = %s", $created_post_id);

        $results = $wpdb->get_results($prepare);
        return empty($results) ? null : $results[0];

    }

    public function get_users_with_processes()
    {
        global $wpdb;

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if ($this->table_exists()) {
            return $wpdb->get_results("select u.id, u.display_name from $wpdb->users u where exists(select id from $table_name_process lp where lp.user_id  = u.id)");
        }
        return array();

    }

    public function get_template_posts()
    {
        global $wpdb;

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if ($this->table_exists()) {
            return $wpdb->get_results("SELECT p.id, p.post_title as title FROM $wpdb->posts p where post_status in ('publish', 'draft','private', 'trash') and exists(select pr.id from $table_name_process pr where pr.post_id = p.id )");
        }
        return array();
    }

    /**
     * How many Page Sets exist, including sets that have no Generated Pages yet: the row is the set,
     * so an empty set still counts. Backs the Overview's Page Sets tile (issue #269), which is why the
     * count comes back as an int rather than the string `$wpdb` hands over.
     */
    public function count_processes(): int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $prepare = $wpdb->prepare("SELECT count(*) as count FROM $table_name_process");
        $rows = $wpdb->get_results($prepare);
        // A failed read answers an empty set, exactly like an empty table would not (count(*) always
        // yields one row); only last_error tells the Overview it must not report zero Page Sets.
        if ($wpdb->last_error) {
            throw new Exception("Failed to count processes " . $wpdb->last_error);
        }
        $result = (array)($rows[0] ?? array('count' => 0));
        return (int)$result['count'];
    }

    /**
     * How many Synced Page Sets the site has: Page Sets with Google Sheet Sync switched on, whether
     * the plugin or the LPagery App manages them, because both sync from a sheet and both belong in
     * the Overview's Google Sheet Sync tile (issue #273). The site-wide switch is not consulted here;
     * a set stays a Synced Page Set while syncing is paused, and the health section says so instead.
     */
    public function count_synced_page_sets(): int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $count = $wpdb->get_var("SELECT count(*) FROM $table_name_process WHERE google_sheet_sync_enabled = 1");
        // get_var() answers null for a failed query, which (int) would silently turn into "no sets".
        if ($wpdb->last_error) {
            throw new Exception("Failed to count synced page sets " . $wpdb->last_error);
        }

        return (int)$count;
    }

    /**
     * Whether this site expects LPagery to run a scheduled sync at all: at least one Synced Page Set
     * the plugin manages. A set the LPagery App manages syncs from the App's own servers, so it says
     * nothing about whether this site's background tasks are alive, which is what the Overview health
     * section asks (issue #273). A row written before the `managing_system` column existed carries no
     * value; the plugin is what was syncing it, which is what the column defaults to today.
     *
     * Answers a boolean rather than a count because the caller only needs to know whether the site is
     * quiet, and LIMIT 1 lets the database stop at the first matching row.
     */
    public function plugin_managed_synced_page_set_exists(): bool
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $found = $wpdb->get_var("SELECT 1 FROM $table_name_process
             WHERE google_sheet_sync_enabled = 1 AND coalesce(managing_system, 'plugin') <> 'app'
             LIMIT 1");
        // get_var() answers null both for "no such row" and for a failed query, so only last_error
        // separates a quiet site from a read the Overview must not report on.
        if ($wpdb->last_error) {
            throw new Exception("Failed to look for a plugin managed synced page set " . $wpdb->last_error);
        }

        return $found !== null;
    }

    /**
     * How many Generated Pages the Synced Page Sets own, for the Overview's Google Sheet Sync tile
     * (issue #273). One page on the site is one page here however many Synced Page Sets claim it, so
     * the count is over DISTINCT posts. Trashed pages are left out, exactly as they are in the
     * headline page total, because a sync will never write to them again.
     */
    public function count_pages_in_synced_page_sets(): int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $count = $wpdb->get_var("SELECT count(DISTINCT lpp.post_id)
             FROM $table_name_process_post lpp
             INNER JOIN $table_name_process lp ON lp.id = lpp.lpagery_process_id
             INNER JOIN $wpdb->posts p ON p.ID = lpp.post_id
             WHERE lp.google_sheet_sync_enabled = 1 AND p.post_status != 'trash'");
        if ($wpdb->last_error) {
            throw new Exception("Failed to count pages in synced page sets " . $wpdb->last_error);
        }

        return (int)$count;
    }

    /**
     * When Google Sheet Sync last finished anywhere on this site: the newest per-set
     * `last_google_sheet_sync`, handed over raw as the UTC datetime string it is stored as
     * (production stamps it with `current_time('mysql', true)`), so the browser can say how long ago
     * that was. Answers null when no Synced Page Set has ever finished a run, which is what the zero
     * date the column defaults to means.
     */
    public function get_last_completed_sheet_sync(): ?string
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        // Both spellings of "never" are excluded by comparing the stored string: the zero date the
        // column defaults to, and the NULL a pre-migration row can carry. The column is written and
        // read as a UTC wall-clock string, so no time-zone conversion belongs anywhere near it.
        $last = $wpdb->get_var("SELECT max(last_google_sheet_sync) FROM $table_name_process
             WHERE google_sheet_sync_enabled = 1
               AND last_google_sheet_sync IS NOT NULL
               AND last_google_sheet_sync != '0000-00-00 00:00:00'");
        if ($wpdb->last_error) {
            throw new Exception("Failed to read the last completed sheet sync " . $wpdb->last_error);
        }

        return $last === null ? null : (string)$last;
    }

    /**
     * How many Generated Pages inside Synced Page Sets somebody edited by hand, for the Overview's
     * Google Sheet Sync card (issue #273). While "overwrite manual changes" is off these are the
     * pages a sync leaves exactly as they are, so the card can say how much of the site the sheet no
     * longer drives. Counted over DISTINCT posts, trashed pages left out, same as the owned-page
     * count, so the two numbers are about the same pages.
     */
    public function count_manually_changed_pages_in_synced_page_sets(): int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $count = $wpdb->get_var("SELECT count(DISTINCT lpp.post_id)
             FROM $table_name_process_post lpp
             INNER JOIN $table_name_process lp ON lp.id = lpp.lpagery_process_id
             INNER JOIN $wpdb->posts p ON p.ID = lpp.post_id
             WHERE lp.google_sheet_sync_enabled = 1 AND p.post_status != 'trash'
               AND lpp.page_manually_updated_at IS NOT NULL
               AND lpp.page_manually_updated_at != '0000-00-00 00:00:00'");
        if ($wpdb->last_error) {
            throw new Exception("Failed to count manually changed pages in synced page sets " . $wpdb->last_error);
        }

        return (int)$count;
    }

    /**
     * The most recently created Page Sets, newest first, for the Overview's recent list (issue #269).
     * One query answers the whole card: the set, its Template Page (title, id and post type, so the
     * card can link into the filtered WordPress post list), its non-trashed page count and the two
     * Render Mode counts behind the observed mode badge. The counts reuse the very subqueries the
     * Manage search runs, so the two surfaces cannot disagree about a set.
     *
     * A Page Set whose Template Page was deleted keeps its row, so `template_title` and `post_type`
     * come back empty and the caller renders the name without a link. `created` is handed over raw,
     * exactly as stored, and formatted where it is displayed.
     *
     * @return array<int, array{id:int,purpose:string,template_id:int,template_title:string,post_type:string,page_count:int,observed_render_mode:string,created:string}>
     */
    public function get_recent_processes(int $limit): array
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $prepare = $wpdb->prepare("SELECT lp.id AS id,
                lp.purpose AS purpose,
                lp.data AS data,
                lp.created AS created,
                lp.post_id AS template_id,
                tpl.post_title AS template_title,
                tpl.post_type AS post_type,
                " . $this->non_trashed_count_subquery() . " AS page_count,
                " . $this->render_mode_count_subquery(true) . " AS live_count,
                " . $this->render_mode_count_subquery(false) . " AS classic_count
            FROM $table_name_process lp
            LEFT JOIN $wpdb->posts tpl ON tpl.ID = lp.post_id
            ORDER BY lp.created DESC, lp.id DESC
            LIMIT %d", $limit);

        $rows = $wpdb->get_results($prepare);
        if ($wpdb->last_error) {
            throw new Exception("Failed to read recent processes " . $wpdb->last_error);
        }

        return array_map(static function ($row) {
            return array(
                'id' => (int)$row->id,
                'purpose' => (string)($row->purpose ?? ''),
                'template_id' => (int)$row->template_id,
                'template_title' => (string)($row->template_title ?? ''),
                'post_type' => (string)($row->post_type ?? ''),
                'page_count' => (int)$row->page_count,
                'observed_render_mode' => ObservedRenderMode::derive(
                    (int)$row->classic_count,
                    (int)$row->live_count,
                    ObservedRenderMode::configured($row->data ?? null)
                ),
                'created' => (string)($row->created ?? ''),
            );
        }, $rows ?: array());
    }

    public function get_first_process_date()
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $prepare = $wpdb->prepare("SELECT created FROM $table_name_process order by created asc limit 1");
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        $result = (array)$results[0];
        return $result['created'];
    }

    public function update_process_template(int $processId, int $templateId)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $previous_template = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $table_name_process WHERE id = %d",
            $processId));
        $wpdb->update($table_name_process, array("post_id" => $templateId), array("id" => $processId));
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->update($table_name_process_post, array("template_id" => $templateId),
            array("lpagery_process_id" => $processId,
                "template_id" => $previous_template));
        if ($wpdb->last_error) {
            throw new Exception("Failed to lpagery_update_process_template  " . $wpdb->last_error);
        }
    }

    public function update_process_user(int $process_id, int $user_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $wpdb->update($table_name_process, array("user_id" => $user_id), array("id" => $process_id));
        if ($wpdb->last_error) {
            throw new Exception("Failed to lpagery_update_process_user  " . $wpdb->last_error);
        }
    }

    public function update_process_managing_system($id, string $managingSystem)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $wpdb->update($table_name_process, array("managing_system" => $managingSystem), array("id" => $id));
    }

    /**
     * Delete the Page Set row itself. Wired last by {@see \LPagery\service\delete\DeleteProcessService}
     * in the historical removal order (view → generated pages + meta → sync queue → process), after
     * every owned child row has been dropped.
     */
    public function delete(int $process_id): void
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $wpdb->delete($table_name_process, array("id" => $process_id));
    }

    /**
     * The correlated scalar subquery that counts a Page Set's non-trashed Generated Pages, shared by
     * every caller that used to copy-paste it (source-post / id / created-post reads and the search
     * SELECT + empty/non-empty filter). Returns just the parenthesized `(select count(...) …)` term
     * WITHOUT any alias or comparison suffix, so each caller appends its own `as count` / `> 0` /
     * `= 0`. Correlates on the outer Page Set alias `lp` (every caller aliases `lpagery_process` as
     * `lp`). The `$process_post_alias` lets {@see get_process_by_created_post_id()} use `lpp_count`
     * so it does not collide with that query's own outer `lpp` join; every other caller uses `lpp`.
     */
    private function non_trashed_count_subquery(string $process_post_alias = 'lpp'): string
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        return "(select count($process_post_alias.id) from $table_name_process_post $process_post_alias inner join $wpdb->posts p on p.id = $process_post_alias.post_id where $process_post_alias.lpagery_process_id = lp.id and p.post_status != 'trash')";
    }

    /**
     * Correlated scalar subquery yielding the Template Page's stub-strip footprint in BYTES — the
     * per-page saving the Strip to Stub service realises
     * when it converts a classic page into a Live Mode stub, and therefore the unit price behind the
     * Reclaimable Size estimate (issue #240).
     *
     * It sums exactly what the strip deletes: the template's `post_content` + `post_content_filtered`
     * plus every post meta EXCEPT the kept ones (`_thumbnail_id` and any `_lpagery_*` tracking key —
     * its kept-meta set / `delete_non_stub_meta`). Row Data is ignored.
     *
     * Returns NULL when the Template Page is trashed or gone (no row matches), which the mapper turns
     * into "no estimate". `LENGTH()` (not `CHAR_LENGTH()`) because the estimate is about database
     * bytes. The `_lpagery_` prefix is matched by a BOUND `%s` pattern escaped with `esc_like()`, so
     * the underscores stay literal and no bare `%` ever reaches `wpdb::prepare()`.
     */
    private function template_footprint_subquery(): string
    {
        global $wpdb;

        return "(select coalesce(length(tpl.post_content), 0) + coalesce(length(tpl.post_content_filtered), 0)"
            . " + coalesce((select sum(length(tpl_meta.meta_value)) from $wpdb->postmeta tpl_meta"
            . " where tpl_meta.post_id = tpl.ID and tpl_meta.meta_key != '_thumbnail_id'"
            . " and tpl_meta.meta_key not like %s), 0)"
            . " from $wpdb->posts tpl where tpl.ID = lp.post_id and tpl.post_status != 'trash')";
    }

    /**
     * Correlated scalar subquery counting a Page Set's non-trashed Generated Pages that are (or are
     * not) Live Mode stubs — the observed Render Mode counts of issue #240. Same set of pages as
     * {@see non_trashed_count_subquery()} (so `live_count + classic_count === count`), partitioned by
     * the per-page `_lpagery_render_mode = 'live'` marker (ADR-0010): a page is live iff the marker
     * says so, and an ABSENT marker counts as classic, which is what every page generated before Live
     * Mode looks like.
     *
     * The marker is probed with EXISTS rather than joined, so a page carrying a duplicate meta row
     * cannot be counted twice. Aliases are suffixed per mode so the two subqueries can sit in the same
     * SELECT list next to the plain count. Correlates on the outer Page Set alias `lp`.
     *
     * @param bool $live true ⇒ count the live stubs, false ⇒ count the classic pages.
     */
    private function render_mode_count_subquery(bool $live): string
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $alias = $live ? 'lpp_live' : 'lpp_classic';
        $post_alias = $live ? 'p_live' : 'p_classic';
        $meta_alias = $live ? 'pm_live' : 'pm_classic';
        $exists = $live ? 'exists' : 'not exists';

        return "(select count($alias.id) from $table_name_process_post $alias"
            . " inner join $wpdb->posts $post_alias on $post_alias.id = $alias.post_id"
            . " where $alias.lpagery_process_id = lp.id and $post_alias.post_status != 'trash'"
            . " and $exists (select 1 from $wpdb->postmeta $meta_alias where $meta_alias.post_id = $alias.post_id"
            . " and $meta_alias.meta_key = '_lpagery_render_mode' and $meta_alias.meta_value = 'live'))";
    }

    /**
     * Does the `lpagery_process_post` table exist? Memoized on the instance (Design C): table
     * existence is monotonic per request, so the first `information_schema` probe is cached for the
     * rest of the request and no write path invalidates it. Backs the {@see get_users_with_processes()}
     * / {@see get_template_posts()} gate that keeps those listings from fataling on a pre-migration DB.
     */
    private function table_exists(): bool
    {
        global $wpdb;

        if ($this->table_exists !== null) {
            return $this->table_exists;
        }

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT
                    TABLE_NAME
                FROM
                    information_schema.TABLES
                WHERE
                        TABLE_NAME = %s
            ) as lpagery_table_exists;", $table_name_process_post);
        $result = (array)$wpdb->get_results($prepare)[0];
        $this->table_exists = (bool)$result['lpagery_table_exists'];

        return $this->table_exists;
    }
}
