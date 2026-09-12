<?php

namespace LPagery\service\overview;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\data\repository\PageSetRepository;
use LPagery\data\repository\SyncQueueRepository;
use LPagery\service\queue\BackgroundWorkerReliability;
use LPagery\service\settings\SettingsController;

/**
 * The read model behind the Overview tab (issue #269). It answers one question: what has LPagery
 * built on this site right now? The snapshot is an inventory, not a history, so every number
 * describes the pages that exist at this moment.
 *
 * The service owns the composition and the calendar arithmetic; every count comes from an aggregate
 * repository method, so no SQL lives here. Nothing is cached: the endpoint is computed per request,
 * which is what keeps the tab agreeing with Manage.
 *
 * Lives in FREE code and reads only free repositories, so the Overview is identical on every tier.
 */
class OverviewSnapshotService
{
    /** How many monthly buckets the created-per-month histogram carries, ending with this month. */
    public const MONTH_WINDOW = 12;

    /** How many Page Sets the recent list carries. Five is what fits the card without scrolling. */
    public const RECENT_LIMIT = 5;

    /**
     * The WP-Cron hook Google Sheet Sync runs on. Named here because the Overview only reads when it
     * is next due; scheduling it is the premium hook's job.
     */
    private const SHEET_SYNC_CRON_HOOK = 'lpagery_sync_google_sheet';

    /** The option holding the schedule name the site syncs on, and the name WordPress ships. */
    private const SHEET_SYNC_INTERVAL_OPTION = 'lpagery_google_sheet_sync_interval';
    private const DEFAULT_SHEET_SYNC_INTERVAL = 'hourly';

    /**
     * How long an hourly run is, in seconds. Used when the saved schedule name is not registered on
     * this site, which is what the free build looks like: the premium `cron_schedules` filter never
     * runs there, so a saved custom interval has no length to read.
     */
    private const DEFAULT_SHEET_SYNC_INTERVAL_SECONDS = 3600;

    private GeneratedPageRepository $generatedPageRepository;
    private PageSetRepository $pageSetRepository;
    private SyncQueueRepository $syncQueueRepository;
    private SettingsController $settingsController;
    private BackgroundWorkerReliability $backgroundWorkerReliability;

    public function __construct(
        GeneratedPageRepository $generatedPageRepository,
        PageSetRepository $pageSetRepository,
        SyncQueueRepository $syncQueueRepository,
        SettingsController $settingsController,
        BackgroundWorkerReliability $backgroundWorkerReliability
    ) {
        $this->generatedPageRepository = $generatedPageRepository;
        $this->pageSetRepository = $pageSetRepository;
        $this->syncQueueRepository = $syncQueueRepository;
        $this->settingsController = $settingsController;
        $this->backgroundWorkerReliability = $backgroundWorkerReliability;
    }

    /**
     * The whole Overview snapshot: the page totals with their status and Render Mode splits, the
     * Page Set total, the health block, the twelve monthly buckets and the most recent Page Sets.
     *
     * The twelve buckets only reach back a year, so a site that generated pages before that reports
     * the rest as `created_per_month_outside_window`. Bars plus that number equal the page total, which
     * is what lets the chart and the headline tile agree on screen.
     *
     * @return array{
     *     pages: array{total:int,published:int,draft:int,other:int},
     *     page_sets: array{total:int},
     *     render_modes: array{classic:int,live:int},
     *     health: array{orphaned_live_pages: array{total:int,trashed_template:int,missing_template:int}, page_sets_with_sync_errors:int, stuck_sync_page_sets:int, background_tasks_stale:bool, wp_cron_disabled:bool, sync_disabled_page_sets:int, pending_sync_items:int},
     *     sheet_sync: array{page_sets:int,pages:int,last_completed:string|null,next_run:string|null,interval:string,site_enabled:bool,overwrite_manual_changes:bool,status:array{up_to_date:int,failed:int,stuck:int,running:int},manually_changed_pages:int},
     *     created_per_month: array<int, array{month:string,count:int}>,
     *     created_per_month_outside_window: int,
     *     recent_page_sets: array<int, array<string, mixed>>
     * }
     */
    public function get_snapshot(): array
    {
        // Google Sheet Sync is read first: the health block reports the stuck sets and the sets the
        // site-wide switch is holding back, both of which are numbers this block already carries, so
        // reading them twice would mean asking the database the same question twice.
        $sheet_sync = $this->build_sheet_sync();
        $history = $this->build_month_history();

        return array(
            'pages' => $this->generatedPageRepository->count_pages_by_status(),
            'page_sets' => array('total' => $this->pageSetRepository->count_processes()),
            'render_modes' => $this->generatedPageRepository->count_pages_by_render_mode(),
            'health' => $this->build_health($sheet_sync),
            'sheet_sync' => $sheet_sync,
            'created_per_month' => $history['buckets'],
            'created_per_month_outside_window' => $history['outside_window'],
            'recent_page_sets' => $this->pageSetRepository->get_recent_processes(self::RECENT_LIMIT),
        );
    }

    /**
     * What needs attention right now: the Live Mode pages that lost their Template Page, the Page Sets
     * whose Google Sheet sync failed, and the sync-queue rows still waiting. Every number is a count of
     * things that exist, so a healthy site answers with zeros and the Overview says so in one row.
     *
     * The orphan scan reads three tables, so it runs only when the site has a Live Mode page at all.
     * A page whose Template Page row is gone and a page whose Template Page is in the trash are
     * reported separately, because only the trashed one can still be brought back by restoring it.
     *
     * Three of the numbers are about Google Sheet Sync (issue #273): the Stuck Syncs and the sets the
     * site-wide switch is holding back both come straight off the sheet-sync block, and the background
     * tasks are reported as idle only on a site that is actually waiting for them.
     *
     * @param array{page_sets:int,site_enabled:bool,status:array{up_to_date:int,failed:int,stuck:int,running:int}} $sheet_sync
     * @return array{orphaned_live_pages: array{total:int,trashed_template:int,missing_template:int}, page_sets_with_sync_errors:int, stuck_sync_page_sets:int, background_tasks_stale:bool, wp_cron_disabled:bool, sync_disabled_page_sets:int, pending_sync_items:int}
     */
    private function build_health(array $sheet_sync): array
    {
        $trashed = 0;
        $missing = 0;
        if ($this->generatedPageRepository->live_stubs_exist()) {
            $orphans = $this->generatedPageRepository->count_orphaned_live_stubs();
            $trashed = (int)$orphans['trashed'];
            $missing = (int)$orphans['missing'];
        }

        return array(
            'orphaned_live_pages' => array(
                'total' => $trashed + $missing,
                'trashed_template' => $trashed,
                'missing_template' => $missing,
            ),
            'page_sets_with_sync_errors' => $this->syncQueueRepository->count_page_sets_with_sync_errors(),
            'stuck_sync_page_sets' => (int)$sheet_sync['status']['stuck'],
            'background_tasks_stale' => $this->background_tasks_are_stale(),
            // Tells the reader where to look when the row shows: at WordPress, or at the server cron
            // that is supposed to call wp-cron.php in its place.
            'wp_cron_disabled' => $this->backgroundWorkerReliability->is_wp_cron_disabled(),
            // A set the switch is holding back is not broken, so it is not in the stuck bucket; it is
            // simply not being synced, and the whole set of them is what the health row names.
            'sync_disabled_page_sets' => $sheet_sync['site_enabled'] ? 0 : (int)$sheet_sync['page_sets'],
            'pending_sync_items' => $this->syncQueueRepository->count_pending_sync_items(),
        );
    }

    /**
     * Whether LPagery's scheduled work has stopped running on this site (issue #273): the worker is
     * stuck, meaning its heartbeat is stale although requests have been giving WP-Cron a chance for
     * longer than the threshold (see {@see BackgroundWorkerReliability::is_worker_stuck()}), and the
     * site is actually waiting for it, either because the plugin manages a Synced Page Set it is meant
     * to sync on a schedule or because a background run is queued right now. A site that has never
     * asked for background work has no heartbeat either, and there is nothing wrong with that; nor is
     * there with a quiet site whose first visitor in hours is the reader opening this page.
     *
     * The stuck check is at most two option reads, so it is asked first and the two database probes
     * only follow a site that has something to be idle about.
     */
    private function background_tasks_are_stale(): bool
    {
        if (!$this->backgroundWorkerReliability->is_worker_stuck()) {
            return false;
        }

        return $this->pageSetRepository->plugin_managed_synced_page_set_exists()
            || $this->syncQueueRepository->active_background_run_exists();
    }

    /**
     * What Google Sheet Sync is doing on this site right now (issue #273): how many Page Sets sync
     * from a sheet and how many Generated Pages they own, when the last run finished and when the
     * next one is due at which interval, how the sets split into up to date / failed / stuck /
     * running, and how many pages somebody edited by hand.
     *
     * The schedule is read here, once, and handed to the repository as plain numbers, so the whole
     * status split stays one query and no calendar arithmetic leaks into SQL-free code or SQL-heavy
     * code twice. `wp_next_scheduled()` answers false when nothing is scheduled, which is what the
     * free build always looks like, and the card then says the site has no next run rather than
     * inventing one. Both timestamps travel as UTC datetime strings, the same form
     * `last_google_sheet_sync` is stored in, so the browser can render them in the reader's zone.
     *
     * @return array{page_sets:int,pages:int,last_completed:string|null,next_run:string|null,interval:string,site_enabled:bool,overwrite_manual_changes:bool,status:array{up_to_date:int,failed:int,stuck:int,running:int},manually_changed_pages:int}
     */
    private function build_sheet_sync(): array
    {
        $interval_name = (string)get_option(self::SHEET_SYNC_INTERVAL_OPTION, self::DEFAULT_SHEET_SYNC_INTERVAL);
        $schedules = wp_get_schedules();
        $interval_seconds = isset($schedules[$interval_name]['interval'])
            ? (int)$schedules[$interval_name]['interval']
            : self::DEFAULT_SHEET_SYNC_INTERVAL_SECONDS;

        $scheduled = wp_next_scheduled(self::SHEET_SYNC_CRON_HOOK);
        $next_run = is_numeric($scheduled) ? (int)$scheduled : null;
        $now = (int)current_time('U', true);

        return array(
            'page_sets' => $this->pageSetRepository->count_synced_page_sets(),
            'pages' => $this->pageSetRepository->count_pages_in_synced_page_sets(),
            'last_completed' => $this->pageSetRepository->get_last_completed_sheet_sync(),
            'next_run' => $next_run === null ? null : gmdate('Y-m-d H:i:s', $next_run),
            'interval' => $interval_name,
            'site_enabled' => $this->settingsController->getSheetSyncEnabled(),
            'overwrite_manual_changes' => $this->settingsController->isOverwriteManualChangesEnabled(),
            'status' => $this->syncQueueRepository->count_synced_page_sets_by_status($interval_seconds, $next_run, $now),
            'manually_changed_pages' => $this->pageSetRepository->count_manually_changed_pages_in_synced_page_sets(),
        );
    }

    /**
     * How the current page inventory was built up: twelve monthly buckets, oldest first, ending with
     * the current month in the site's timezone, plus the pages that were created before that window.
     * Every bucket exists even when it is zero, so a month in which nothing was generated still shows
     * on the chart as an empty slot.
     *
     * The anchor is the leading `Y-m` of `current_time('mysql')`, which is the very clock the
     * Generated Page `created` column is stamped with, so the window and the rows that fill it agree
     * by construction. The months are then counted out arithmetically rather than derived from
     * timestamps, so the window can never slide by a day because the server clock sits in a different
     * timezone than the site.
     *
     * @return array{buckets: array<int, array{month:string,count:int}>, outside_window:int}
     */
    private function build_month_history(): array
    {
        $anchor = explode('-', substr((string)current_time('mysql'), 0, 7));
        $anchor_year = (int)($anchor[0] ?? 0);
        $anchor_month = (int)($anchor[1] ?? 1);
        $anchor_index = $anchor_year * 12 + ($anchor_month - 1);

        $counts = $this->generatedPageRepository->count_pages_created_per_month();

        $buckets = array();
        $inside_window = 0;
        for ($offset = self::MONTH_WINDOW - 1; $offset >= 0; $offset--) {
            $index = $anchor_index - $offset;
            $month = sprintf('%04d-%02d', intdiv($index, 12), ($index % 12) + 1);
            $count = (int)($counts[$month] ?? 0);
            $inside_window += $count;
            $buckets[] = array('month' => $month, 'count' => $count);
        }

        return array(
            'buckets' => $buckets,
            'outside_window' => array_sum(array_map('intval', $counts)) - $inside_window,
        );
    }
}
