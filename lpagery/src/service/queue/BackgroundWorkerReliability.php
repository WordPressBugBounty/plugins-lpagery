<?php

namespace LPagery\service\queue;

use LPagery\data\repository\SyncQueueRepository;
use LPagery\utils\Utils;

/**
 * Reliability for background runs on real hosts (issue #220 Phase 6). Three concerns, one seam:
 *
 *  - **Loopback kick** ({@see self::kick()}): on enqueue we fire a non-blocking loopback HTTP request
 *    that runs the queue worker now, so a healthy host starts within seconds instead of waiting for the
 *    5-minute safety-net cron. Fire-and-forget — we never read the response. Mirrors the item-execution
 *    loopback ({@see \LPagery\service\sheet_sync\GoogleSheetSyncRestClient}) but guards its own secret;
 *    the spawned request is validated by {@see self::validate_kick_secret()} before it runs the worker.
 *  - **Heartbeat** ({@see self::record_heartbeat()}): the worker stamps a liveness timestamp each tick.
 *  - **Request opportunity stamp** ({@see self::record_request_opportunity()}): WP-Cron only runs when a
 *    request hits the site, so a quiet site has a stale heartbeat for the innocent reason that nothing
 *    gave cron a chance. Every request stamps the first moment since the last heartbeat that cron *had*
 *    a chance; the heartbeat clears the stamp.
 *  - **Staleness watchdog** ({@see self::is_stalled()} / {@see self::is_worker_stuck()}): a run that has
 *    waited past the threshold while the worker is stuck surfaces as "background tasks aren't running on
 *    this site" instead of an indefinite "Queued…". Stuck means the heartbeat is stale AND requests have
 *    been arriving for longer than the threshold without it refreshing (or WP-Cron is disabled, so time
 *    alone is the opportunity). It is evaluated at *read time* (from the mapper), never on the cron tick,
 *    so it cannot itself depend on cron working — cron being dead is exactly the failure it detects.
 *    Cancel stays the escape hatch: a stalled run still holds the per-set lock until the user cancels it.
 *
 * This lives in FREE code: the kick only fires HTTP (the worker endpoint it targets is premium), the
 * heartbeat/enqueue stamps are plain options, and the watchdog reads options + the free repository — so
 * the free-build mapper can derive the stalled state without reaching into any premium graph.
 */
class BackgroundWorkerReliability
{
    /** Global "the worker last ran at" unix timestamp — written by the worker, read by the watchdog. */
    private const HEARTBEAT_OPTION = 'lpagery_queue_worker_heartbeat';

    /**
     * Unix timestamp of the first request seen since the last heartbeat — the moment WP-Cron first had a
     * chance to run and did not. Written once per heartbeat cycle, deleted by the heartbeat.
     */
    private const FIRST_REQUEST_SINCE_HEARTBEAT_OPTION = 'lpagery_queue_worker_first_request_since_heartbeat';

    /** Secret guarding the loopback worker endpoint; rotated on every kick. */
    private const KICK_SECRET_OPTION = 'lpagery_queue_worker_kick_secret';

    /** Per-set "this run was enqueued at" unix timestamp — how the watchdog measures the wait. */
    private const RUN_ENQUEUED_OPTION_PREFIX = 'lpagery_background_run_enqueued_';

    /**
     * How long a run may wait, how stale the heartbeat may be, and how long requests must have been
     * arriving unanswered, before the watchdog flags the worker. Kept comfortably above the 5-minute
     * (300s) safety-net worker cron so a host that merely relies on cron — loopback blocked but cron
     * alive — is never falsely flagged: the next cron tick refreshes the heartbeat and drains the queue
     * well inside this window. Two missed ticks is the story the number tells.
     */
    public const STALE_THRESHOLD_SECONDS = 600;

    private SyncQueueRepository $syncQueueRepository;

    public function __construct(SyncQueueRepository $syncQueueRepository)
    {
        $this->syncQueueRepository = $syncQueueRepository;
    }

    /**
     * Fire the non-blocking loopback that runs the worker now. Rotates the guard secret, then POSTs it
     * to the secret-guarded worker endpoint with `blocking => false` so the caller's request returns
     * immediately and the worker runs in the spawned request.
     */
    public function kick(): void
    {
        $secret = Utils::generateRandomString(32);
        delete_option(self::KICK_SECRET_OPTION);
        add_option(self::KICK_SECRET_OPTION, $secret, '', false);

        $url = rest_url('lpagery/v1/run_queue_worker');
        wp_remote_post($url, array(
            'method' => 'POST',
            'timeout' => 1,
            'blocking' => false,
            'sslverify' => false,
            'body' => array('secret' => $secret),
            'cookies' => array(),
        ));
    }

    /**
     * Validate a kick secret against the stored one (constant-time). Read uncached because the spawned
     * loopback runs in a separate request from the one that rotated the secret.
     */
    public function validate_kick_secret(?string $secret): bool
    {
        if (!is_string($secret) || $secret === '') {
            return false;
        }
        $stored = Utils::get_uncached_option(self::KICK_SECRET_OPTION);
        return is_string($stored) && $stored !== '' && hash_equals($stored, $secret);
    }

    /**
     * Record worker liveness. Called at the start of every worker tick so the watchdog can tell a run
     * that is progressing (fresh heartbeat) from a site where the worker never runs (stale heartbeat).
     */
    public function record_heartbeat(): void
    {
        update_option(self::HEARTBEAT_OPTION, $this->now(), false);
        // The worker just ran, so whatever chance cron had before this is answered.
        delete_option(self::FIRST_REQUEST_SINCE_HEARTBEAT_OPTION);
    }

    /**
     * Note that a request reached the site, which is what gives WP-Cron a chance to run. Only the first
     * request since the last heartbeat is stamped, so a busy site pays one cached option read per
     * request and one write per heartbeat cycle. Hooked on `init` for every request, front end included.
     */
    public function record_request_opportunity(): void
    {
        if ($this->read_timestamp_option(self::FIRST_REQUEST_SINCE_HEARTBEAT_OPTION) !== null) {
            return;
        }
        update_option(self::FIRST_REQUEST_SINCE_HEARTBEAT_OPTION, $this->now(), false);
    }

    /**
     * Stamp when a background run was enqueued, so the watchdog can measure how long it has waited.
     * Overwritten on each new run; stale leftovers are harmless because {@see self::is_stalled()} only
     * consults it while the set actually holds an active background operation.
     */
    public function record_run_enqueued(int $process_id): void
    {
        update_option(self::RUN_ENQUEUED_OPTION_PREFIX . $process_id, $this->now(), false);
    }

    /**
     * True when the set's active background run has stalled: it holds a background operation, has waited
     * past the threshold, and the worker is stuck ({@see self::is_worker_stuck()}) — i.e. nothing is
     * draining the queue on this site although it had the chance.
     */
    public function is_stalled(int $process_id): bool
    {
        if (!$this->syncQueueRepository->has_active_background_operation($process_id)) {
            return false;
        }

        $enqueued_at = $this->read_timestamp_option(self::RUN_ENQUEUED_OPTION_PREFIX . $process_id);
        if ($enqueued_at === null) {
            return false;
        }

        $now = $this->now();
        if (($now - $enqueued_at) <= self::STALE_THRESHOLD_SECONDS) {
            return false;
        }

        return $this->is_worker_stuck();
    }

    /**
     * True when the worker is stuck: nothing is draining the queue on this site *although it had the
     * chance*. The heartbeat must be stale ({@see self::is_heartbeat_stale()}), and on top of that:
     *
     *  - with WP-Cron enabled, requests must have been arriving for longer than the threshold without
     *    the heartbeat refreshing. A quiet site whose first visitor in hours is the admin opening the
     *    dashboard is not stuck: that very request has just spawned cron, and the stamp is seconds old.
     *  - with `DISABLE_WP_CRON` set, page views are no opportunity at all; a server cron calls
     *    wp-cron.php on its own clock, so a stale heartbeat alone means that cron is not doing its job.
     *
     * This is the site-wide half of {@see self::is_stalled()}, exposed on its own so the Overview health
     * section (issue #273) can say "background tasks have not run for a while" without a run to hang it
     * on, and without any caller having to know the option names.
     */
    public function is_worker_stuck(): bool
    {
        if (!$this->is_heartbeat_stale()) {
            return false;
        }

        if ($this->is_wp_cron_disabled()) {
            return true;
        }

        $first_request = $this->read_timestamp_option(self::FIRST_REQUEST_SINCE_HEARTBEAT_OPTION);
        if ($first_request === null) {
            return false;
        }

        return ($this->now() - $first_request) > self::STALE_THRESHOLD_SECONDS;
    }

    /**
     * Whether this site runs cron from a server schedule instead of from page views. Read through a
     * seam so tests can pin it without defining a global constant.
     */
    public function is_wp_cron_disabled(): bool
    {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    /**
     * True when the worker has never recorded a heartbeat, or its last one is older than the threshold.
     * On its own this cannot tell a stuck worker from a quiet site; {@see self::is_worker_stuck()} adds
     * the opportunity check, and is what callers should ask.
     */
    public function is_heartbeat_stale(): bool
    {
        $heartbeat = $this->read_timestamp_option(self::HEARTBEAT_OPTION);
        if ($heartbeat === null) {
            return true;
        }

        return ($this->now() - $heartbeat) > self::STALE_THRESHOLD_SECONDS;
    }

    private function read_timestamp_option(string $name): ?int
    {
        $value = get_option($name);
        if ($value === false || $value === '' || $value === null) {
            return null;
        }
        return (int)$value;
    }

    /**
     * Current unix time, isolated behind a seam so tests can pin "now" without mocking the PHP builtin.
     */
    public function now(): int
    {
        return time();
    }
}
