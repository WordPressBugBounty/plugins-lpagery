<?php

namespace LPagery\service;

/**
 * The three decisions LPagery makes from how old an install is: the free placeholder-column limit,
 * whether tracking was allowed from the start, and the free per-run page limit.
 *
 * The date comes from the `lpagery_installation_date` option, which
 * {@see \LPagery\data\LPageryDatabaseMigrator::record_installation_date_if_missing()} records once
 * (issue #276). This class deliberately never touches `$wpdb`: the old implementation ran an
 * information_schema query per decision on every admin page load, which is slow on shared hosting
 * where the server holds hundreds of databases. Reading an autoloaded option costs nothing.
 *
 * A missing option (a site restored from a backup without it, before the next admin load re-records
 * it) falls back to the answer a brand-new install gets: the limits apply, tracking is not allowed.
 */
class InstallationDateHandler
{
    /** The WordPress option holding the install date as a MySQL datetime string (`Y-m-d H:i:s`). */
    public const OPTION_NAME = 'lpagery_installation_date';

    /** Installs created up to this date have no placeholder-column limit on the free plan. */
    private const PLACEHOLDER_LIMIT_THRESHOLD = '2023-09-04 00:00:00';

    /** Installs created from this date on were told about tracking when they were set up. */
    private const TRACKING_THRESHOLD = '2024-12-14 00:00:00';

    /** Installs created from this date on are capped at 100 pages per run on the free plan. */
    private const MAX_PAGES_THRESHOLD = '2025-09-19 00:00:00';

    public function __construct()
    {
    }

    /**
     * The free plan's placeholder-column limit, or null when there is none.
     *
     * @return int|null
     */
    public function get_placeholder_counts()
    {
        $installation_date = $this->get_installation_date();
        if ($installation_date !== '' && $installation_date <= self::PLACEHOLDER_LIMIT_THRESHOLD) {
            return null;
        }
        if (lpagery_fs()->is_free_plan()) {
            return 3;
        }
        return null;
    }

    /**
     * Whether tracking was permitted from the start, which only applies to installs created from
     * the date the tracking notice shipped onwards.
     */
    public function initial_tracking_allowed(): bool
    {
        $installation_date = $this->get_installation_date();
        return $installation_date !== '' && $installation_date >= self::TRACKING_THRESHOLD;
    }

    /**
     * The free plan's per-run page limit, or null when there is none.
     *
     * @return int|null
     */
    public function get_max_pages_per_run()
    {
        $installation_date = $this->get_installation_date();
        $created_after_threshold = $installation_date === '' || $installation_date >= self::MAX_PAGES_THRESHOLD;
        if ($created_after_threshold && lpagery_fs()->is_free_plan()) {
            return 100;
        }
        return null;
    }

    /**
     * The recorded installation date as a `Y-m-d H:i:s` string, or an empty string when the option
     * is missing. Dates in that format compare correctly as plain strings.
     */
    private function get_installation_date(): string
    {
        $installation_date = get_option(self::OPTION_NAME, false);
        return is_string($installation_date) ? trim($installation_date) : '';
    }
}
