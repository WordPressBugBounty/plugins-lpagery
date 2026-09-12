<?php

namespace LPagery\io\hooks;

/**
 * Registers the per-request stamp behind the background worker watchdog: every request that reaches
 * `init` is a chance for WP-Cron to run, and {@see \LPagery\service\queue\BackgroundWorkerReliability}
 * remembers the first such chance since the worker last heartbeated. Without it the watchdog could not
 * tell a stuck worker from a quiet site nobody has visited for a while.
 *
 * Registered unconditionally in FREE code: the watchdog itself is free so the free-build mapper can
 * derive the stalled state, and a site that upgrades to premium should not start with no stamp history.
 */
class BackgroundWorkerHooks
{
    public static function register(): void
    {
        add_action('init', [self::class, 'record_request_opportunity']);
    }

    public static function record_request_opportunity(): void
    {
        lpagery_root()->backgroundWorkerReliability()->record_request_opportunity();
    }
}
