<?php

namespace LPagery\service\queue;

/**
 * The single source of truth for turning a Page Set's raw run-record status plus its per-set queue
 * counts into the status the dashboard renders (issue #220 Phase 7). Both the Manage mapper
 * ({@see \LPagery\io\Mapper::get_google_sheet_sync_details}) and the live status endpoint
 * ({@see BackgroundRunStatusService}) derive through here, so sheet sync is just one case of the
 * generalized background-operation status — the two surfaces can never drift.
 *
 * Pure and WordPress-free: the staleness decision is computed by the caller (from
 * {@see BackgroundWorkerReliability}) and passed in, so this stays exhaustively unit-testable.
 */
final class BackgroundRunStatus
{
    /**
     * Still-active statuses — a run in one of these is executing or waiting, so it can transition and
     * is the only kind that can stall. Terminal states (FINISHED, ERROR, CANCELLED, PAST_DUE) and the
     * idle PLANNED state are deliberately excluded.
     */
    private const ACTIVE_STATUSES = array('QUEUED', 'RUNNING', 'WAITING_FOR_PROCESSING', 'PAUSED_WAITING_FOR_NEXT_BATCH');

    /**
     * Derive the rendered status from the raw run-record status and the live queue counts.
     *
     * @param string $raw_status the persisted `google_sheet_sync_status` (may be empty ⇒ PLANNED)
     * @param int    $pending    queue rows still waiting to be processed (error IS NULL)
     * @param int    $errored    queue rows that failed at least once (error IS NOT NULL)
     * @param bool   $stalled    whether the staleness watchdog has flagged this run (Phase 6)
     */
    public static function derive(string $raw_status, int $pending, int $errored, bool $stalled): string
    {
        $status = $raw_status !== '' ? $raw_status : 'PLANNED';

        // An active run whose queue has fully drained has finished, even if the row still says RUNNING.
        if (in_array($status, array('RUNNING', 'PAUSED_WAITING_FOR_NEXT_BATCH', 'WAITING_FOR_PROCESSING'), true)
            && $pending === 0) {
            $status = 'FINISHED';
        }

        // A finished run with leftover queue rows reflects the queue, not the stored status: errored
        // rows surface as ERROR, still-pending rows as WAITING_FOR_PROCESSING (pending wins, matching
        // the historical Manage mapper order).
        if ($status === 'FINISHED') {
            if ($errored > 0) {
                $status = 'ERROR';
            }
            if ($pending > 0) {
                $status = 'WAITING_FOR_PROCESSING';
            }
        }

        // The staleness overlay only applies while the run is still active — a run the worker never
        // picked up surfaces as STALLED instead of an indefinite "Queued…".
        if ($stalled && in_array($status, self::ACTIVE_STATUSES, true)) {
            $status = 'STALLED';
        }

        return $status;
    }

    /**
     * Whether a derived status still counts as an in-flight run — drives the plugin-wide "background
     * run active" indicator and adaptive polling. STALLED is included: it holds the per-set lock and
     * needs a visible, actionable indicator until the user cancels it.
     */
    public static function is_active(string $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true)
            || $status === 'STALLED'
            || $status === 'CRON_STARTED'
            || $status === 'DOWNLOADING_DATA';
    }
}
