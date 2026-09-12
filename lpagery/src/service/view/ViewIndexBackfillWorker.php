<?php

namespace LPagery\service\view;

use LPagery\data\repository\PageMetaIndexRepository;

/**
 * Chunked, idempotent backfill of the sparse meta index for a newly-Indexed (process, key)
 * pair (ADR-0001, #166).
 *
 * When a View introduces a match key the Process didn't index before, that one key must be
 * populated across all the Process's pages. On a 100k-page combination Process that cannot run
 * synchronously, so it is enqueued and drained a bounded batch at a time across cron ticks. The
 * View is usable immediately: until the key's backfill completes its readiness flag stays false,
 * so the resolver serves it via the live-deserialization fallback (#163 behaviour-invariant).
 *
 * Job-state storage: an option-backed queue. A single WP option (`lpagery_view_backfill_jobs`)
 * holds the list of pending jobs, each `{ process_id, key, cursor }`, where `cursor` is the last
 * processed post_id. This mirrors the per-(process,key) readiness flag already stored as an
 * option by ViewMetaIndexService, keeping all backfill state in one consistent mechanism with no
 * new schema/migration. Each tick processes one batch for the head job, advances its cursor, and
 * on the final (empty) batch flips the key ready and drops the job.
 *
 * The option is written back whole, so every read-modify-write of it runs under one named MySQL
 * lock (PageMetaIndexRepository::acquire_backfill_queue_lock): an enqueue landing while a cron tick
 * drains a batch would otherwise be overwritten by the tick's write and the job silently lost. A
 * tick that finds the lock taken skips itself, which also keeps two overlapping cron runs from
 * indexing the same batch twice; an enqueue waits briefly and then proceeds regardless, since a
 * queued job that might collide beats a View whose key is never backfilled.
 *
 * Idempotent on re-run: each page's meta row is written through
 * PageMetaIndexRepository::sync_page (-> PageMetaIndexRepository::upsert_post_meta), a
 * delete-then-insert per (post_id, key). Re-running a batch — or replaying a whole job — produces
 * the same single row per key, never duplicates. Re-enqueueing an already-ready/already-pending
 * key is a no-op.
 *
 * License-agnostic: backfill execution is never premium-gated. Introducing a new Indexed key
 * (creating a View) is the premium action; populating/maintaining the index for an
 * already-Indexed key must keep running on downgraded sites (ADR-0002).
 */
class ViewIndexBackfillWorker
{
    public const JOBS_OPTION = 'lpagery_view_backfill_jobs';
    public const BATCH_SIZE = 200;
    // How long an enqueue waits for a draining tick to hand the queue back before writing anyway.
    private const ENQUEUE_LOCK_WAIT_SECONDS = 5;

    private PageMetaIndexRepository $pageMetaIndexRepository;
    private ViewMetaIndexService $viewMetaIndexService;

    public function __construct(PageMetaIndexRepository $pageMetaIndexRepository, ViewMetaIndexService $viewMetaIndexService)
    {
        $this->pageMetaIndexRepository = $pageMetaIndexRepository;
        $this->viewMetaIndexService = $viewMetaIndexService;
    }

    /**
     * Enqueue a chunked backfill for (process, key) if it is an Indexed key that is not already
     * ready and not already queued. Does NOT block the caller: the View is usable immediately and
     * an "indexing" state (is_key_indexing) holds until the job drains. Schedules an immediate
     * single tick so small Processes finish fast, and relies on the recurring cron for the rest.
     */
    public function enqueue(int $process_id, string $key): void
    {
        // An "all" selection-mode View stores match_key = '' and indexes nothing; never enqueue a
        // backfill for the empty key (ADR-0005).
        if ($key === '') {
            return;
        }
        if (!$this->viewMetaIndexService->is_indexed_key($process_id, $key)) {
            return;
        }
        if ($this->viewMetaIndexService->is_key_ready($process_id, $key)) {
            return;
        }

        $locked = $this->pageMetaIndexRepository->acquire_backfill_queue_lock(self::ENQUEUE_LOCK_WAIT_SECONDS);
        try {
            $jobs = $this->read_jobs();
            if ($this->find_job_index($jobs, $process_id, $key) !== null) {
                return; // already pending
            }

            $jobs[] = array("process_id" => $process_id, "key" => $key, "cursor" => 0);
            $this->write_jobs($jobs);
        } finally {
            if ($locked) {
                $this->pageMetaIndexRepository->release_backfill_queue_lock();
            }
        }

        // Immediate first tick for small Processes; the recurring cron drains the rest.
        if (!wp_next_scheduled('lpagery_view_backfill_cron_event')) {
            wp_schedule_single_event(time(), 'lpagery_view_backfill_cron_event');
        }
    }

    /**
     * Drain one batch of the head pending job. Re-invoked tick after tick (cron) until the queue
     * is empty. Processing the head job to completion before moving on keeps progress monotonic
     * and the cursor meaningful.
     */
    public function process(): void
    {
        // Another tick is draining the queue right now: leave it to that one rather than index the
        // same batch twice or overwrite its cursor write.
        if (!$this->pageMetaIndexRepository->acquire_backfill_queue_lock(0)) {
            return;
        }
        try {
            $this->process_locked();
        } finally {
            $this->pageMetaIndexRepository->release_backfill_queue_lock();
        }
    }

    private function process_locked(): void
    {
        $jobs = $this->read_jobs();
        if (empty($jobs)) {
            return;
        }

        wp_raise_memory_limit("cron");

        $job = $jobs[0];
        $process_id = (int)$job["process_id"];
        $key = (string)$job["key"];
        $cursor = (int)$job["cursor"];

        // The last View using this key may have been deleted mid-backfill. Once the key is no longer
        // an Indexed key, sync_page stops writing it, so finishing the job would flip a partial index
        // ready — and a later re-add would skip enqueue (already "ready") and serve that partial set.
        // Drop the stale job and clear readiness instead, so re-adding the key re-backfills cleanly.
        if (!$this->viewMetaIndexService->is_indexed_key($process_id, $key)) {
            $this->viewMetaIndexService->set_key_ready($process_id, $key, false);
            array_shift($jobs);
            $this->write_jobs($jobs);
            return;
        }

        $rows = $this->pageMetaIndexRepository->get_process_posts_for_backfill($process_id, $cursor, self::BATCH_SIZE);

        if (empty($rows)) {
            // No more pages: the key is fully indexed. Flip it ready and drop the job.
            $this->viewMetaIndexService->set_key_ready($process_id, $key, true);
            array_shift($jobs);
            $this->write_jobs($jobs);
            return;
        }

        $last_post_id = $cursor;
        foreach ($rows as $row) {
            $post_id = (int)$row->post_id;
            $raw_data = maybe_unserialize($row->data);
            if (!is_array($raw_data)) {
                $raw_data = array();
            }

            // Reuse the single index seam so normalization stays in one place and the backfill
            // path cannot diverge from incremental sync. sync_page upserts every Indexed key for
            // the Process — a deliberate superset of the target key: it makes the whole batch
            // consistent and stays idempotent (delete-then-insert), at no extra correctness cost.
            $this->pageMetaIndexRepository->sync_page($process_id, $post_id, $raw_data);

            if ($post_id > $last_post_id) {
                $last_post_id = $post_id;
            }
        }

        $jobs[0]["cursor"] = $last_post_id;
        $this->write_jobs($jobs);

        // More pages likely remain; keep the recurring cron draining. Also nudge an immediate
        // follow-up tick so a small Process that spans a couple of batches finishes promptly.
        if (count($rows) >= self::BATCH_SIZE && !wp_next_scheduled('lpagery_view_backfill_cron_event')) {
            wp_schedule_single_event(time(), 'lpagery_view_backfill_cron_event');
        }
    }

    /**
     * "Indexing" state for the builder UI (#167): an Indexed key whose backfill is still pending
     * (a job exists for it) and which has not yet been flipped ready. While true, the resolver
     * serves the key via the live fallback.
     */
    public function is_key_indexing(int $process_id, string $key): bool
    {
        if ($this->viewMetaIndexService->is_key_ready($process_id, $key)) {
            return false;
        }
        return $this->find_job_index($this->read_jobs(), $process_id, $key) !== null;
    }

    /**
     * @return array<int, array{process_id:int,key:string,cursor:int}>
     */
    private function read_jobs(): array
    {
        $jobs = get_option(self::JOBS_OPTION, array());
        return is_array($jobs) ? $jobs : array();
    }

    /**
     * @param array<int, array> $jobs
     */
    private function write_jobs(array $jobs): void
    {
        if (empty($jobs)) {
            delete_option(self::JOBS_OPTION);
            return;
        }
        update_option(self::JOBS_OPTION, array_values($jobs), false);
    }

    /**
     * @param array<int, array> $jobs
     */
    private function find_job_index(array $jobs, int $process_id, string $key): ?int
    {
        foreach ($jobs as $index => $job) {
            if ((int)$job["process_id"] === $process_id && (string)$job["key"] === $key) {
                return $index;
            }
        }
        return null;
    }
}
