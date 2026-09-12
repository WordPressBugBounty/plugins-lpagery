<?php

namespace LPagery\data\repository;

use LPagery\service\view\ViewValueNormalizer;

/**
 * Data access for the sparse Page meta index (`lpagery_process_post_meta`, ADR-0001) that backs
 * related-pages View matching, plus the index-maintenance seam that keeps it current.
 *
 * The index is sparse: rows exist only for Indexed keys — placeholder keys currently used as some
 * View's match key for the Process — so the resolver can serve match candidates with an exact
 * equality lookup instead of deserializing every page. This repository is the one focused seam for
 * this table's SQL (plus its two read-only companions, `lpagery_view` for the Indexed-key set and
 * `lpagery_process_post` for the backfill scan) lives in one focused seam.
 *
 * Stateless — each method reaches for `global $wpdb`; there is no constructor state. This is plain
 * FREE code with no premium gate: index maintenance is intentionally license-agnostic (ADR-0002
 * downgrade safety), so nothing here may touch `lpagery_fs()` or premium-only symbols.
 *
 * ## Contract notes (matching the legacy DAO shapes byte-for-byte)
 *
 * - {@see get_post_ids_by_meta()} returns `int[]` of matching post ids; both the stored value (on
 *   write) and the lookup value (on read) are truncated to the varchar(191) index width so a value
 *   longer than 191 chars still matches its indexed row.
 * - {@see get_process_posts_for_backfill()} returns RAW `$wpdb` rows of `{ post_id, data }` — `data`
 *   is left serialized (the caller unserializes), matching what the DAO returned.
 * - {@see sync_page()} is the one index-maintenance path: it normalizes each Indexed key's value
 *   through the shared {@see ViewValueNormalizer} so the index-backed resolver query stays
 *   byte-identical to the live-deserialization path (ADR-0001 / slice #163).
 */
class PageMetaIndexRepository
{
    /**
     * The distinct set of Indexed keys for a Process: every key currently used as the
     * match key by at least one of the Process's Views. Bounds index size (ADR-0001).
     *
     * @return string[] match keys
     */
    public function get_indexed_keys_for_process(int $process_id): array
    {
        global $wpdb;
        $table_name_view = $wpdb->prefix . 'lpagery_view';

        // Exclude the empty match key: an "all" selection-mode View stores match_key = '' and joins
        // on nothing, so it must never count as an Indexed key (no index rows, no backfill).
        $prepare = $wpdb->prepare("select distinct match_key from $table_name_view where process_id = %d and match_key != ''", $process_id);
        $rows = $wpdb->get_results($prepare);

        return array_map(static function ($row) {
            return (string)$row->match_key;
        }, $rows);
    }

    /**
     * Refresh the meta index for a single page across the Process's currently-Indexed keys — the
     * one index-maintenance seam (create/update page write path). No-op (beyond a cheap key lookup)
     * when the Process has no Views, keeping the common case nearly free.
     *
     * Each Indexed key's value is normalized through the shared {@see ViewValueNormalizer} so the
     * index-backed resolver query compares with exact equality and stays byte-identical to the
     * live-deserialization path (ADR-0001 / slice #163). A missing/empty value clears that key's row.
     *
     * @param array<string, mixed> $raw_data The page's placeholder data (key => value).
     */
    public function sync_page(int $process_id, int $post_id, array $raw_data): void
    {
        $indexed_keys = $this->get_indexed_keys_for_process($process_id);
        if (empty($indexed_keys)) {
            return;
        }

        foreach ($indexed_keys as $key) {
            $raw_value = array_key_exists($key, $raw_data) && $raw_data[$key] !== null
                ? (string)$raw_data[$key]
                : null;
            // Store the value normalized through the shared rule so the index-backed resolver
            // query can compare with exact equality and stay identical to the live path.
            $value = ($raw_value === null || $raw_value === "") ? null : ViewValueNormalizer::normalize($raw_value);
            $this->upsert_post_meta($post_id, $process_id, $key, $value);
        }
    }

    /**
     * Replace a single page's meta rows for one Indexed key with the given value. Delete-then
     * -insert keeps the operation idempotent (re-running yields the same single row, no dupes).
     * Omitting/empty $value just clears the row (the page has no value for that key).
     */
    public function upsert_post_meta(int $post_id, int $process_id, string $meta_key, ?string $meta_value): void
    {
        global $wpdb;
        $table_name_meta = $wpdb->prefix . 'lpagery_process_post_meta';

        $wpdb->delete($table_name_meta, array("post_id" => $post_id, "meta_key" => $meta_key));

        if ($meta_value === null || $meta_value === "") {
            return;
        }

        // varchar(191) index column: truncate defensively to the indexable length.
        $stored_value = function_exists('mb_substr') ? mb_substr($meta_value, 0, 191) : substr($meta_value, 0, 191);

        $wpdb->insert($table_name_meta, array(
            "post_id" => $post_id,
            "process_id" => $process_id,
            "meta_key" => $meta_key,
            "meta_value" => $stored_value,
        ));
    }

    /**
     * Remove all meta rows for a single page (used on page delete/trash).
     */
    public function delete_post_meta(int $post_id): void
    {
        global $wpdb;
        $table_name_meta = $wpdb->prefix . 'lpagery_process_post_meta';
        $wpdb->delete($table_name_meta, array("post_id" => $post_id));
    }

    /**
     * Remove all meta rows for a whole Process (used on process delete).
     */
    public function delete_process_meta(int $process_id): void
    {
        global $wpdb;
        $table_name_meta = $wpdb->prefix . 'lpagery_process_post_meta';
        $wpdb->delete($table_name_meta, array("process_id" => $process_id));
    }

    /**
     * Index-backed resolver read: post ids of a Process's *published* pages whose Indexed-key
     * value matches. The index itself stays sparse and status-agnostic (ADR-0001); the linkability
     * filter lives here, at read time, on the join to `wp_posts`, so a View never returns a sibling
     * a visitor cannot open. The caller passes a value already normalized by the resolver's
     * normalize_value() seam and stores meta values normalized the same way, so the comparison
     * here is an exact equality — keeping the index path byte-identical to the live-fallback
     * path under whatever normalization rule the resolver currently applies. Returns ids
     * ordered by post_id for a stable baseline; ordering presets are applied higher up.
     *
     * @return int[] matching post ids
     */
    public function get_post_ids_by_meta(int $process_id, string $meta_key, string $normalized_value): array
    {
        global $wpdb;
        $table_name_meta = $wpdb->prefix . 'lpagery_process_post_meta';

        // Stored meta_value is truncated to the varchar(191) index width on write
        // (upsert_post_meta); truncate the lookup value the same way so a value longer
        // than 191 chars still matches its indexed row instead of silently disappearing.
        $normalized_value = function_exists('mb_substr')
            ? mb_substr($normalized_value, 0, 191)
            : substr($normalized_value, 0, 191);

        $prepare = $wpdb->prepare(
            "select m.post_id as post_id
                from $table_name_meta m
                inner join $wpdb->posts p on p.id = m.post_id
                where m.process_id = %d and m.meta_key = %s and m.meta_value = %s
                  and p.post_status = 'publish'
                order by m.post_id",
            $process_id, $meta_key, $normalized_value);

        $rows = $wpdb->get_results($prepare);

        return array_map(static function ($row) {
            return (int)$row->post_id;
        }, $rows);
    }

    /**
     * Backfill helper (used by #166's chunked job): a bounded batch of (post_id, data) for a
     * Process, paginated by post_id so successive ticks cover the whole Process. Idempotent at
     * the row level because the caller upserts via {@see upsert_post_meta} (delete-then-insert).
     * Returns RAW `$wpdb` rows with `data` left serialized — the backfill worker unserializes.
     *
     * @return array<int, object> rows of { post_id, data }
     */
    public function get_process_posts_for_backfill(int $process_id, int $after_post_id, int $limit): array
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare(
            "select lpp.post_id as post_id, lpp.data as data
                from $table_name_process_post lpp
                inner join $wpdb->posts p on p.id = lpp.post_id
                where lpp.lpagery_process_id = %d and lpp.post_id > %d
                  and p.post_status not in ('trash', 'auto-draft')
                order by lpp.post_id
                limit %d",
            $process_id, $after_post_id, $limit);

        return $wpdb->get_results($prepare);
    }

    /**
     * Take the named MySQL lock that serializes writers of the option-backed backfill queue
     * (`ViewIndexBackfillWorker::JOBS_OPTION`). The queue is read, mutated and written back as one
     * value, so an admin request enqueueing a job while a cron tick drains one would otherwise
     * overwrite each other's write and drop a pending job. Waits up to $timeout_seconds (0 = try
     * once); returns whether this connection now holds the lock. The lock is per connection and
     * MySQL drops it with the connection, so a fatal mid-tick cannot wedge the queue.
     */
    public function acquire_backfill_queue_lock(int $timeout_seconds): bool
    {
        global $wpdb;
        $acquired = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, %d)",
            $this->backfill_queue_lock_name(), $timeout_seconds
        ));
        return (string)$acquired === '1';
    }

    public function release_backfill_queue_lock(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $this->backfill_queue_lock_name()));
    }

    /**
     * Named locks are server-wide, so the table prefix keeps sites sharing one MySQL server apart.
     */
    private function backfill_queue_lock_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'lpagery_view_backfill_queue';
    }
}
