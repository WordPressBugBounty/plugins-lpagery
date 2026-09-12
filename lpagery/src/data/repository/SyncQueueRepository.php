<?php

namespace LPagery\data\repository;

use Exception;
use LPagery\model\SyncQueueItem;
use LPagery\service\queue\QueueOperation;

/**
 * Data access for the Google-Sheet sync workflow: the `lpagery_sync_queue` table (the per-page
 * download/retry queue) plus the Google-Sheet sync columns that live on `lpagery_process`
 * (`google_sheet_data`, `google_sheet_sync_enabled`, `google_sheet_sync_status`,
 * `google_sheet_sync_error`, `last_google_sheet_sync`).
 *
 * The one focused seam for the sync SQL, following the #195 {@see ViewRepository} tracer. The method
 * names are domain-normalized (the `lpagery_` prefix is dropped).
 */
class SyncQueueRepository
{
    public function save_process_sheet_data($process_id, $google_sheet_data, $google_sheet_sync_enabled)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $wpdb->update($table_name_process, array("google_sheet_data" => serialize($google_sheet_data),
            "google_sheet_sync_enabled" => $google_sheet_sync_enabled), array("id" => $process_id));


        return $process_id;
    }

    public function get_processes_with_google_sheet_sync()
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $results = $wpdb->get_results("select id, data, post_id, created, google_sheet_data
            from $table_name_process where google_sheet_sync_enabled");
        return array_map(function ($element) {
            return array("id" => $element->id,
                "created" => $element->created,
                "data" => maybe_unserialize($element->data),
                "google_sheet_data" => maybe_unserialize($element->google_sheet_data),
                "post_id" => $element->post_id);
        }, $results);
    }

    public function get_process_for_google_sheet_sync($process_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $results = $wpdb->get_results($wpdb->prepare("select id, data, post_id, created, google_sheet_data
            from $table_name_process where google_sheet_sync_enabled and id = %s and managing_system !='app'", $process_id));
        if (empty($results)) {
            return null;
        }
        $result = (array)$results[0];
        return array("id" => $result['id'],
            "created" => $result['created'],
            "data" => maybe_unserialize($result['data']),
            "google_sheet_data" => maybe_unserialize($result['google_sheet_data']),
            "post_id" => $result['post_id']);
    }

    public function update_process_sync_status($process_id, $status, $error = null)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if ($status == "ERROR") {
            $wpdb->update($table_name_process, array("google_sheet_sync_status" => $status,
                "google_sheet_sync_error" => $error), array("id" => $process_id));
        } elseif ($status == "FINISHED") {
            $wpdb->update($table_name_process, array("google_sheet_sync_status" => $status,
                "google_sheet_sync_error" => null,
                "last_google_sheet_sync" => current_time('mysql', true)), array("id" => $process_id));
        } else {
            $wpdb->update($table_name_process, array("google_sheet_sync_status" => $status), array("id" => $process_id));
        }
    }

    public function search_queue_items($process_id, $type, $slug)
    {
        global $wpdb;
        $table_name_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $where_conditions = ["process_id = %d"];
        $query_params = [$process_id];

        // Add type filter
        if ($type === 'error') {
            $where_conditions[] = "error IS NOT NULL";
        } elseif ($type === 'queue') {
            $where_conditions[] = "error IS NULL";
        }

        // Add slug filter if provided
        if (!empty($slug)) {
            $where_conditions[] = "slug LIKE %s";
            $query_params[] = '%' . $wpdb->esc_like($slug) . '%';
        }

        $where_clause = implode(' AND ', $where_conditions);
        $prepare = $wpdb->prepare("SELECT id, slug, retry as retry_count, error
            FROM $table_name_queue
            WHERE $where_clause
            LIMIT 1000", ...$query_params);

        return $wpdb->get_results($prepare);
    }

    public function is_sheet_data_downloading(): bool
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT id
                FROM $table_name_process
                WHERE google_sheet_sync_enabled
                AND google_sheet_sync_status IN ('DOWNLOADING_DATA', 'CRON_STARTED')
            ) as is_syncing;");
        $result = (array)$wpdb->get_results($prepare)[0];
        return boolval($result['is_syncing']);
    }

    public function get_next_set_to_be_synced(): ?int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $next_id = $wpdb->get_var("SELECT id from $table_name_process where google_sheet_sync_status = 'CRON_STARTED' and google_sheet_sync_enabled LIMIT 1");

        if ($next_id) {
            return intval($next_id);
        }
        return null;

    }

    /**
     * Delete every queued sync row for a Page Set. Wired by {@see \LPagery\service\delete\DeleteProcessService},
     * which runs this between the Generated Page and Page Set deletes in the historical removal order
     * (view → generated pages + meta → sync queue → process).
     */
    public function delete_by_process(int $process_id): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'lpagery_sync_queue', array("process_id" => $process_id));
    }

    // --- Background run record (lpagery_process) -----------------------------

    /**
     * Load the Page Set as the {@see \LPagery\service\queue\DataDocumentQueueExpander} needs it for a
     * background run: id, unserialized config `data` and the Template Page's `post_id`. Mirrors
     * {@see self::get_process_for_google_sheet_sync()} but without the sheet-sync gating, since a
     * background run is not tied to Google-Sheet sync being enabled.
     *
     * @return array<string, mixed>|null
     */
    public function get_process_for_background_run(int $process_id): ?array
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $results = $wpdb->get_results($wpdb->prepare("select id, data, post_id, created
            from $table_name_process where id = %d", $process_id));
        if (empty($results)) {
            return null;
        }
        $result = (array)$results[0];
        return array("id" => $result['id'],
            "created" => $result['created'],
            "data" => maybe_unserialize($result['data']),
            "post_id" => $result['post_id']);
    }

    /**
     * Persist an accepted upload that awaits expansion: the raw gzip byte stream (base64-encoded so the
     * binary survives the LONGTEXT column) plus the run params it should expand under. Stored in the
     * *compressed* form deliberately — the serialized PHP document of a large upload can exceed MySQL's
     * `max_allowed_packet` and fail the write silently, while its gzip source stays megabytes small.
     *
     * @param array<string, mixed> $run_params the {@see \LPagery\service\queue\BackgroundRunParams} as an array
     */
    public function save_pending_expansion_document(int $process_id, string $gzip_bytes, array $run_params): void
    {
        global $wpdb;
        $payload = array(
            'pending_gzip_b64' => base64_encode($gzip_bytes),
            'run_params' => $run_params,
        );
        $wpdb->update($wpdb->prefix . 'lpagery_process',
            array('background_run_document' => maybe_serialize($payload)),
            array('id' => $process_id));
    }

    /**
     * Read back a stored pending-expansion payload, or null when none is stored (nothing there, or the
     * blob holds another shape — e.g. a multi-part upload still in flight).
     *
     * @return array{gzip_bytes:string,run_params:array<string, mixed>}|null
     */
    public function get_pending_expansion_document(int $process_id): ?array
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $value = $wpdb->get_var($wpdb->prepare("SELECT background_run_document FROM $table_name_process WHERE id = %d",
            $process_id));
        if ($value === null || $value === '') {
            return null;
        }
        $payload = maybe_unserialize($value);
        if (!is_array($payload) || !isset($payload['pending_gzip_b64'])) {
            return null;
        }
        $decoded = base64_decode((string)$payload['pending_gzip_b64'], true);
        if ($decoded === false) {
            return null;
        }
        return array(
            'gzip_bytes' => $decoded,
            'run_params' => is_array($payload['run_params'] ?? null) ? $payload['run_params'] : array(),
        );
    }

    /**
     * Atomically claim a pending expansion by clearing the blob: only one worker tick wins (the
     * safety-net cron can race a loopback kick), and the winner expands the document it already read.
     * Returns true when this caller claimed it.
     */
    public function claim_pending_expansion(int $process_id): bool
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE $table_name_process SET background_run_document = NULL
             WHERE id = %d AND background_run_document IS NOT NULL",
            $process_id));
        return $affected === 1;
    }

    /**
     * The Page Sets whose accepted upload still awaits expansion: QUEUED (the pre-expansion lock state)
     * with a stored run blob. May include a multi-part upload still in flight — the caller filters by
     * shape via {@see self::get_pending_expansion_document()}.
     *
     * @return array<int, int>
     */
    public function fetch_pending_expansion_process_ids(): array
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $ids = $wpdb->get_col("SELECT id FROM $table_name_process
             WHERE google_sheet_sync_status = 'QUEUED' AND background_run_document IS NOT NULL");
        return array_map('intval', $ids ?: array());
    }

    /**
     * Persist an in-progress multi-part upload (issue #220 Phase 8): the raw gzip bytes accumulated so
     * far, base64-encoded so the binary survives the LONGTEXT column, plus how many parts have arrived
     * and the total expected. Reuses the same `background_run_document` blob the single-POST path and the
     * final expansion use — so Cancel, which nulls that column, discards a partial upload for free.
     */
    public function save_partial_upload_document(int $process_id, string $gzip_bytes, int $parts_received, int $total_parts): void
    {
        global $wpdb;
        $payload = array(
            'partial_gzip_b64' => base64_encode($gzip_bytes),
            'parts_received' => $parts_received,
            'total_parts' => $total_parts,
        );
        $wpdb->update($wpdb->prefix . 'lpagery_process',
            array('background_run_document' => maybe_serialize($payload)),
            array('id' => $process_id));
    }

    /**
     * Read back an in-progress multi-part upload, or null when none is stored (nothing there, or the
     * blob holds a non-partial shape). Returns the decoded raw gzip bytes and the part counters.
     *
     * @return array{gzip_bytes:string,parts_received:int,total_parts:int}|null
     */
    public function get_partial_upload_document(int $process_id): ?array
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $value = $wpdb->get_var($wpdb->prepare("SELECT background_run_document FROM $table_name_process WHERE id = %d",
            $process_id));
        if ($value === null || $value === '') {
            return null;
        }
        $payload = maybe_unserialize($value);
        if (!is_array($payload) || !isset($payload['partial_gzip_b64'])) {
            return null;
        }
        $decoded = base64_decode((string)$payload['partial_gzip_b64'], true);
        if ($decoded === false) {
            return null;
        }
        return array(
            'gzip_bytes' => $decoded,
            'parts_received' => (int)($payload['parts_received'] ?? 0),
            'total_parts' => (int)($payload['total_parts'] ?? 0),
        );
    }

    /**
     * The total number of rows queued for a Page Set (the N in the N-of-M progress display).
     */
    public function get_queue_count(int $process_id): int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $count = $wpdb->get_var($wpdb->prepare("SELECT queue_count FROM $table_name_process WHERE id = %d",
            $process_id));
        return (int)$count;
    }

    // --- Per-set background-operation lock (issue #220 Phase 5) ---------------

    /**
     * Run-record statuses that mark an in-flight background/sync operation holding the per-set lock.
     * Terminal states (FINISHED, ERROR, CANCELLED, PAST_DUE) and NULL/empty do not hold the lock.
     */
    private const ACTIVE_STATUSES = array('QUEUED', 'PLANNED', 'CRON_STARTED', 'DOWNLOADING_DATA', 'WAITING_FOR_PROCESSING', 'RUNNING');

    /**
     * True when the Page Set has any queued/running operation — the lock every producer (browser run,
     * background enqueue) checks: an in-flight run-record status, or queued items in the shared queue.
     * One active operation per set is the concurrency contract.
     */
    public function has_active_operation(int $process_id): bool
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '%s'));
        $params = array_merge(array($process_id), self::ACTIVE_STATUSES, array($process_id));
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT EXISTS (
                SELECT 1 FROM $table_name_process WHERE id = %d AND google_sheet_sync_status IN ($placeholders)
                UNION ALL
                SELECT 1 FROM $table_name_sync_queue WHERE process_id = %d
            )",
            ...$params
        ));
        return boolval($exists);
    }

    /**
     * True when the Page Set is held by a *background* (non-sheet-sync) operation. Unlike
     * {@see self::has_active_operation()} this deliberately ignores sheet sync's own status transitions
     * and queue items, so the sheet-sync producer can reject a trigger that would race a background run
     * without ever rejecting itself: the QUEUED pre-expansion marker or any non-`sheet_sync` queue item.
     */
    public function has_active_background_operation(int $process_id): bool
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT EXISTS (
                SELECT 1 FROM $table_name_process WHERE id = %d AND google_sheet_sync_status = 'QUEUED'
                UNION ALL
                SELECT 1 FROM $table_name_sync_queue WHERE process_id = %d AND operation <> 'sheet_sync'
            )",
            $process_id, $process_id
        ));
        return boolval($exists);
    }

    /**
     * Atomically claim the per-set lock for a background run, the moment the upload begins. A single
     * conditional UPDATE flips the run-record status to QUEUED only when the set has no in-flight status
     * and no queued items — so two producers can't both win, and nothing races mid-upload. Returns true
     * when this caller acquired the lock, false when the set is already busy (reject the enqueue).
     */
    public function try_acquire_background_lock(int $process_id): bool
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '%s'));
        $params = array_merge(array($process_id), self::ACTIVE_STATUSES, array($process_id));
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE $table_name_process
             SET google_sheet_sync_status = 'QUEUED'
             WHERE id = %d
               AND (google_sheet_sync_status IS NULL OR google_sheet_sync_status NOT IN ($placeholders))
               AND NOT EXISTS (SELECT 1 FROM $table_name_sync_queue WHERE process_id = %d)",
            ...$params
        ));
        return $affected === 1;
    }

    /**
     * Cancel the set's active background operation in one coordinated write: delete every queued item,
     * discard any pending upload document, zero the progress counters, and surface the terminal CANCELLED
     * state — releasing the lock so the set is immediately usable again. Returns the queue rows removed.
     */
    public function cancel_background_operation(int $process_id): int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $removed = (int)$wpdb->delete($wpdb->prefix . 'lpagery_sync_queue', array('process_id' => $process_id));
        $wpdb->update($table_name_process,
            array(
                'background_run_document' => null,
                'queue_count' => 0,
                'processed_queue_count' => 0,
                'google_sheet_sync_status' => 'CANCELLED',
            ),
            array('id' => $process_id));
        return $removed;
    }

    // --- Live run status (issue #220 Phase 7) --------------------------------

    /**
     * Statuses that mean a run is executing or waiting right now — the set of "active runs" the live
     * status endpoint reports. Deliberately excludes the idle PLANNED state (a scheduled but dormant
     * sheet sync) and every terminal state, so an idle site returns an empty snapshot.
     */
    private const RUNNING_STATUSES = array('QUEUED', 'CRON_STARTED', 'DOWNLOADING_DATA', 'RUNNING', 'WAITING_FOR_PROCESSING', 'PAUSED_WAITING_FOR_NEXT_BATCH');

    /**
     * The single indexed query behind the Phase 7 status endpoint: run state + done/total + failures
     * for every Page Set with an active background run, in one round-trip. The per-set queue counts are
     * a single grouped aggregate over `lpagery_sync_queue` (indexed on `process_id`) joined to the
     * process rows (PK lookup); a set qualifies when its run-record status is still active or it has any
     * pending queue rows. Sheet-sync runs are included — they are just one case of the generalized
     * status. Status derivation and the staleness overlay happen above, in {@see BackgroundRunStatusService}.
     *
     * @return array<int, array{process_id:int,raw_status:string,queue_count:int,processed_queue_count:int,pending_count:int,errored_count:int}>
     */
    public function get_active_background_run_statuses(): array
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $placeholders = implode(',', array_fill(0, count(self::RUNNING_STATUSES), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id AS process_id,
                    p.google_sheet_sync_status AS raw_status,
                    p.queue_count AS queue_count,
                    p.processed_queue_count AS processed_queue_count,
                    COALESCE(q.pending_count, 0) AS pending_count,
                    COALESCE(q.errored_count, 0) AS errored_count
             FROM $table_name_process p
             LEFT JOIN (
                 SELECT process_id,
                        SUM(CASE WHEN error IS NULL THEN 1 ELSE 0 END) AS pending_count,
                        SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) AS errored_count
                 FROM $table_name_sync_queue
                 GROUP BY process_id
             ) q ON q.process_id = p.id
             WHERE p.google_sheet_sync_status IN ($placeholders) OR q.pending_count > 0",
            ...self::RUNNING_STATUSES
        ), ARRAY_A);

        return array_map(static function ($row) {
            return array(
                'process_id' => (int)$row['process_id'],
                'raw_status' => (string)$row['raw_status'],
                'queue_count' => (int)$row['queue_count'],
                'processed_queue_count' => (int)$row['processed_queue_count'],
                'pending_count' => (int)$row['pending_count'],
                'errored_count' => (int)$row['errored_count'],
            );
        }, $rows ?: array());
    }

    /**
     * Whether anything on this site is currently waiting for the queue worker: a Page Set whose run
     * is still going, or a queue row that has not been processed and has not stopped on an error.
     * This is the existence question behind {@see get_active_background_run_statuses()}, asked for the
     * Overview health section (issue #273), which only needs to know whether the site expects the
     * worker to be doing something before it reports a stale heartbeat.
     *
     * Two indexed lookups joined by a UNION that stops at the first row, rather than the grouped
     * per-set aggregate the status endpoint builds.
     */
    public function active_background_run_exists(): bool
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $placeholders = implode(',', array_fill(0, count(self::RUNNING_STATUSES), '%s'));

        $found = $wpdb->get_var($wpdb->prepare(
            "(SELECT 1 AS active FROM $table_name_process WHERE google_sheet_sync_status IN ($placeholders) LIMIT 1)
             UNION ALL
             (SELECT 1 AS active FROM $table_name_sync_queue WHERE error IS NULL LIMIT 1)
             LIMIT 1",
            ...self::RUNNING_STATUSES
        ));
        // get_var() answers null both for "nothing is running" and for a failed query.
        if ($wpdb->last_error) {
            throw new Exception("Failed to look for an active background run " . $wpdb->last_error);
        }

        return $found !== null;
    }

    // --- Overview aggregates (issue #269) ------------------------------------

    /**
     * How many Page Sets currently have a Google Sheet sync problem, for the Overview health section.
     * A set counts once however many errored queue rows it carries, and it also counts when the sync
     * itself failed before any row was queued, which is what the terminal ERROR run status records.
     * The two cases are unioned on the set id, so a set that hit both is still one problem to fix.
     */
    public function count_page_sets_with_sync_errors(): int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';

        // The queue and the run-status column are shared with background generation (issue #220),
        // so both halves are scoped to Google Sheet Sync: queue rows by their operation, and the
        // process status by the set having sheet sync switched on. A failed background run is
        // reported by the background-run status instead.
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT count(*) FROM (
                SELECT process_id AS id FROM $table_name_sync_queue
                 WHERE operation = %s AND error IS NOT NULL
                UNION
                SELECT id FROM $table_name_process
                 WHERE google_sheet_sync_enabled = 1 AND google_sheet_sync_status = 'ERROR'
             ) broken",
            QueueOperation::SHEET_SYNC
        ));
        // get_var() answers null for a failed query, which (int) would turn into "no errors".
        if ($wpdb->last_error) {
            throw new Exception("Failed to count page sets with sync errors " . $wpdb->last_error);
        }

        return (int)$count;
    }

    /**
     * How many sync-queue rows are still waiting to be processed site-wide, for the Overview health
     * section. A row without an error is work still in flight; a row that carries one has stopped and
     * is reported by {@see count_page_sets_with_sync_errors()} instead. The two partitions together
     * are the whole queue, the same split the Manage row shows per set.
     */
    public function count_pending_sync_items(): int
    {
        global $wpdb;
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT count(*) FROM $table_name_sync_queue WHERE operation = %s AND error IS NULL",
            QueueOperation::SHEET_SYNC
        ));
        if ($wpdb->last_error) {
            throw new Exception("Failed to count pending sync items " . $wpdb->last_error);
        }

        return (int)$count;
    }

    /**
     * How long after a due run LPagery still treats the schedule as merely late rather than stuck,
     * in seconds. WP-Cron only fires on a page load, so a quiet site is routinely a few minutes
     * behind; a quarter of an hour on top of the interval is what separates that from a sync that
     * has stopped. The same grace is what the Manage row's PAST_DUE derivation uses.
     */
    private const SYNC_GRACE_SECONDS = 900;

    /**
     * The statuses that mean a Google Sheet Sync run is happening right now. This is
     * {@see ACTIVE_STATUSES} without PLANNED, which is the idle state a set sits in between runs
     * rather than a run of its own.
     */
    private const SYNC_RUNNING_STATUSES = array('QUEUED', 'CRON_STARTED', 'DOWNLOADING_DATA', 'WAITING_FOR_PROCESSING', 'RUNNING');

    /**
     * How the Synced Page Sets split into failed / stuck / running / up to date right now, for the
     * Overview's Google Sheet Sync card (issue #273). Every set lands in exactly one bucket, so the
     * four numbers add up to the Synced Page Set count.
     *
     * First match wins, worst first. A set is `failed` when its run ended in an error or it carries
     * an errored sheet-sync queue row. It is `stuck` when the plugin is the one syncing it and either
     * a run started and never reached the queue, or the schedule itself is more than an interval plus
     * the grace overdue while nothing has finished in the last quarter of an hour. That second rule is
     * the PAST_DUE derivation the Manage row shows ({@see \LPagery\io\Mapper}), so an overdue
     * schedule cannot read as stuck on one screen and healthy on the other. A set the LPagery App
     * syncs is never stuck: its runs are driven from the App, not from this site's WP-Cron.
     * Everything still moving is `running`, and what is left is `up_to_date`.
     *
     * All of the arithmetic happens in SQL against the three parameters the caller reads once per
     * snapshot, so the whole split is one grouped query however many Page Sets the site has.
     *
     * @param int $interval_seconds How often the sheet-sync schedule is meant to run.
     * @param int|null $next_run When the schedule is next due, as a UTC unix timestamp, or null when
     *                           nothing is scheduled (the free build never schedules it).
     * @param int $now The current UTC unix timestamp.
     * @return array{up_to_date:int,failed:int,stuck:int,running:int}
     */
    public function count_synced_page_sets_by_status(int $interval_seconds, ?int $next_run, int $now): array
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $running_placeholders = implode(',', array_fill(0, count(self::SYNC_RUNNING_STATUSES), '%s'));

        // The two thresholds the last sync is measured against, spelled as UTC datetime strings
        // rather than as epochs. `last_google_sheet_sync` is written as a UTC wall-clock string and
        // read back as that same string, so comparing strings is right whatever time zone the
        // database itself runs in; unix_timestamp() would fold the server's offset into the answer
        // and report a healthy set as stuck on every site that is not on UTC.
        $overdue_before = gmdate('Y-m-d H:i:s', $now - $interval_seconds - self::SYNC_GRACE_SECONDS);
        $grace_before = gmdate('Y-m-d H:i:s', $now - self::SYNC_GRACE_SECONDS);
        // The zero date the column defaults to (and the NULL a pre-migration row can carry) is older
        // than every threshold, which is what "never synced" should count as.
        $last_sync = "coalesce(lp.last_google_sheet_sync, '0000-00-00 00:00:00')";

        // In the order the placeholders appear in the query below: the running statuses, then the
        // stuck arithmetic, then the queue partition.
        $params = array_merge(
            self::SYNC_RUNNING_STATUSES,
            array(
                $overdue_before,
                $next_run === null ? 0 : 1,
                $next_run === null ? 0 : $next_run,
                $now, $interval_seconds, self::SYNC_GRACE_SECONDS,
                $grace_before,
            ),
            array(QueueOperation::SHEET_SYNC)
        );

        $prepare = $wpdb->prepare(
            "SELECT
                sum(case when failed = 1 then 1 else 0 end) AS failed,
                sum(case when failed = 0 and stuck = 1 then 1 else 0 end) AS stuck,
                sum(case when failed = 0 and stuck = 0 and running = 1 then 1 else 0 end) AS running,
                sum(case when failed = 0 and stuck = 0 and running = 0 then 1 else 0 end) AS up_to_date
             FROM (
                SELECT
                    case when lp.google_sheet_sync_status = 'ERROR' or coalesce(q.errored_rows, 0) > 0
                         then 1 else 0 end AS failed,
                    case when lp.google_sheet_sync_status in ($running_placeholders) or coalesce(q.pending_rows, 0) > 0
                         then 1 else 0 end AS running,
                    case when coalesce(lp.managing_system, 'plugin') <> 'app' and (
                             (lp.google_sheet_sync_status in ('CRON_STARTED', 'DOWNLOADING_DATA')
                                and $last_sync < %s)
                             or (%d = 1 and %d < %d - %d - %d and $last_sync < %s)
                         ) then 1 else 0 end AS stuck
                FROM $table_name_process lp
                LEFT JOIN (
                    SELECT process_id,
                           sum(case when error is not null then 1 else 0 end) AS errored_rows,
                           sum(case when error is null then 1 else 0 end) AS pending_rows
                      FROM $table_name_sync_queue WHERE operation = %s GROUP BY process_id
                ) q ON q.process_id = lp.id
                WHERE lp.google_sheet_sync_enabled = 1
             ) synced_sets",
            $params
        );

        $rows = $wpdb->get_results($prepare, ARRAY_A);
        // A failed read answers an empty set, which the fallbacks below would report as an all-clear.
        if ($wpdb->last_error) {
            throw new Exception("Failed to split synced page sets by status " . $wpdb->last_error);
        }

        $row = (array)($rows[0] ?? array());

        return array(
            'up_to_date' => (int)($row['up_to_date'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'stuck' => (int)($row['stuck'] ?? 0),
            'running' => (int)($row['running'] ?? 0),
        );
    }

    // --- Queue lifecycle -----------------------------------------------------

    /**
     * Enqueue a page for sync, or refresh the row that already represents it. Identity is
     * (process_id, slug, parent_id) only — a matching row is refreshed regardless of its stored
     * payload/action, which fixes the historical defect where a queued row whose sheet Row Data had
     * changed was silently left stale (the old UPDATE additionally required the *old* hashed_payload
     * and existing_page_update_action to equal the new values, so it never matched and never rewrote
     * the hash). On refresh, Row Data, hashed_payload, created and existing_page_update_action are
     * always rewritten; the flags (status_from_dashboard, force_update, overwrite_manual_changes,
     * publish_timestamp) are only upgraded, never downgraded, matching the legacy enqueue intent.
     *
     * `publish_timestamp` is stored as the raw ISO8601 value the dashboard sent (when it parses),
     * unifying the two legacy branches — the downstream page create path parses it with
     * `iso8601_to_datetime()`, which the update branch's `get_gmt_from_date()` space-format broke.
     *
     * Serialization (`maybe_serialize`) of the Row Data happens here, inside the seam.
     */
    public function enqueue_or_refresh(int $process_id, SyncQueueItem $item): void
    {
        global $wpdb;
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $publish_timestamp = ($item->publish_timestamp && strtotime($item->publish_timestamp))
            ? $item->publish_timestamp
            : null;

        $existing_entry = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name_sync_queue WHERE process_id = %d AND slug = %s and parent_id = %d",
            $process_id, $item->slug, $item->parent_id));

        if ($existing_entry > 0) {
            $update_data = array('data' => maybe_serialize($item->data),
                'hashed_payload' => $item->hashed_payload,
                'existing_page_update_action' => $item->existing_page_update_action,
                'created' => current_time('mysql'));
            if ($item->status_from_dashboard && $item->status_from_dashboard !== '-1') {
                $update_data["status_from_dashboard"] = $item->status_from_dashboard;
            }
            if ($item->force_update) {
                $update_data["force_update"] = true;
            }
            if ($item->overwrite_manual_changes) {
                $update_data["overwrite_manual_changes"] = true;
            }
            if ($publish_timestamp) {
                $update_data["publish_timestamp"] = $publish_timestamp;
            }
            $wpdb->update($table_name_sync_queue, $update_data, array('process_id' => $process_id,
                'slug' => $item->slug,
                'parent_id' => $item->parent_id));
        } else {
            $wpdb->insert($table_name_sync_queue, array('process_id' => $process_id,
                'operation' => $item->operation,
                'data' => maybe_serialize($item->data),
                'creation_id' => $item->creation_id,
                'slug' => $item->slug,
                'parent_id' => $item->parent_id,
                'status_from_dashboard' => $item->status_from_dashboard ?? '-1',
                'force_update' => $item->force_update,
                'overwrite_manual_changes' => $item->overwrite_manual_changes,
                "existing_page_update_action" => $item->existing_page_update_action,
                'publish_timestamp' => $publish_timestamp,
                'hashed_payload' => $item->hashed_payload,
                'created' => current_time('mysql')));
        }
    }

    /**
     * Enqueue a single background Render Mode switch item (issue #220 Phase 10). Unlike the document-fed
     * operations there is no upload and no slug-based dedup: the set-level enqueue expands the set's
     * existing Generated Pages into one item per page, each carrying its own `post_id` + `target_mode`
     * in the `data` blob so the worker's loopback can convert exactly that page through the shared
     * conversion service. A plain insert is safe because the per-set lock guarantees an empty queue at
     * enqueue time; `slug` (a NOT NULL column) is set to the post id so every item stays distinct.
     */
    public function enqueue_render_mode_switch_item(int $process_id, int $post_id, string $target_mode, string $creation_id): void
    {
        global $wpdb;
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $wpdb->insert($table_name_sync_queue, array(
            'process_id' => $process_id,
            'operation' => QueueOperation::RENDER_MODE_SWITCH,
            'data' => maybe_serialize(array('post_id' => $post_id, 'target_mode' => $target_mode)),
            'creation_id' => $creation_id,
            'slug' => (string)$post_id,
            'parent_id' => 0,
            'created' => current_time('mysql'),
        ));
    }

    /**
     * Distinct Page-Set ids that have queued rows, ordered ascending — the worker's outer loop.
     *
     * @return int[]
     */
    public function fetch_process_ids(): array
    {
        global $wpdb;
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $ids = $wpdb->get_col("SELECT DISTINCT process_id FROM $table_name_sync_queue ORDER BY process_id ASC");
        return array_map('intval', $ids);
    }

    /**
     * All queued items for a Page Set, ordered by id — the worker's inner loop.
     *
     * @return SyncQueueItem[]
     */
    public function fetch_items(int $process_id): array
    {
        global $wpdb;
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name_sync_queue WHERE process_id = %d ORDER BY id ASC",
            $process_id));
        return array_map(static function ($row) {
            return SyncQueueItem::from_row((array)$row);
        }, $rows);
    }

    /**
     * A single queued item by id, or null when it no longer exists — replaces both the worker's
     * exists-check and the REST endpoint's `SELECT *`.
     */
    public function find_item_by_id(int $item_id): ?SyncQueueItem
    {
        global $wpdb;
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name_sync_queue WHERE id = %d", $item_id),
            ARRAY_A);
        if (!$row) {
            return null;
        }
        return SyncQueueItem::from_row($row);
    }

    /**
     * Remove a queued item that finished successfully.
     */
    public function complete(int $item_id): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'lpagery_sync_queue', array('id' => $item_id));
    }

    /**
     * Record a failed processing attempt. The give-up policy lives here and nowhere else: after the
     * item has already failed three times (retry > 2), the row is deleted instead of retried. Returns
     * true when it gave up (so the worker can log/act), false when it was re-queued for another try.
     */
    public function record_failure(int $item_id, string $error): bool
    {
        global $wpdb;
        $table_name_sync_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $retry = $wpdb->get_var($wpdb->prepare("SELECT retry FROM $table_name_sync_queue WHERE id = %d", $item_id));
        if ($retry === null) {
            return false;
        }
        if ((int)$retry > 2) {
            $wpdb->delete($table_name_sync_queue, array('id' => $item_id));
            return true;
        }
        $wpdb->query($wpdb->prepare("UPDATE $table_name_sync_queue SET retry = retry + 1, error = %s WHERE id = %d",
            $error, $item_id));
        return false;
    }

    // --- Page-Set sync counters (lpagery_process) ----------------------------

    /**
     * Zero both sync counters on a Page Set at the start of a download.
     */
    public function reset_counts(int $process_id): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'lpagery_process',
            array('queue_count' => 0, 'processed_queue_count' => 0),
            array('id' => $process_id));
    }

    /**
     * Set the total number of rows queued for a Page Set.
     */
    public function set_queue_count(int $process_id, int $count): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'lpagery_process',
            array('queue_count' => $count),
            array('id' => $process_id));
    }

    /**
     * Bump the processed counter for a Page Set by one as the worker drains a row.
     */
    public function increment_processed_count(int $process_id): void
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $wpdb->query($wpdb->prepare("UPDATE $table_name_process SET processed_queue_count = processed_queue_count + 1 WHERE id = %d",
            $process_id));
    }
}
