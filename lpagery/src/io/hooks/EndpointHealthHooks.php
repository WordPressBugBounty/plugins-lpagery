<?php

namespace LPagery\io\hooks;

/**
 * Registers the **Endpoint Health** probe triggers (ADR 0014): a daily cron, a re-probe on
 * permalink-structure change, and a version-stamped one-off on activation/upgrade. The generation-run
 * completion trigger fires from BOTH generation paths: the background queue worker's completion drain
 * (schedules {@see self::PROBE_HOOK} directly) and the foreground browser-chunked run's last chunk
 * ({@see \LPagery\controller\CreatePostController} via {@see self::schedule_probe()}).
 *
 * All triggers land on {@see \LPagery\service\image_endpoint\EndpointHealthProbe::probe()}, which no-ops
 * when no live stub carries a Virtual Image Map. Registered unconditionally in FREE code (ADR 0009/0013):
 * serving resilience must survive a lapsed license.
 */
class EndpointHealthHooks
{
    /** Fired by the immediate triggers (permalink change, generation completion, activation/upgrade). */
    public const PROBE_HOOK = 'lpagery_endpoint_health_probe';

    /** The recurring daily probe — a distinct hook so its `wp_next_scheduled` guard never conflates with
     *  a pending one-off {@see self::PROBE_HOOK} event. */
    private const CRON_HOOK = 'lpagery_endpoint_health_probe_daily';

    private const VERSION_OPTION = 'lpagery_endpoint_health_probe_version';
    /** Bump to force a one-off re-probe on the next request after an upgrade. */
    private const PROBE_VERSION = '1';

    public static function register(): void
    {
        add_action(self::PROBE_HOOK, [self::class, 'run_probe']);
        add_action(self::CRON_HOOK, [self::class, 'run_probe']);

        // A permalink-structure switch changes whether the Image Endpoint can route: plain→pretty may
        // recover it, pretty→plain degrades it. Re-probe so the persisted verdict reconciles either way.
        add_action('update_option_permalink_structure', [self::class, 'schedule_probe']);

        // Daily cron + version-stamped activation/upgrade one-off, evaluated once per request on init.
        add_action('init', [self::class, 'ensure_scheduled']);
    }

    public static function run_probe(): void
    {
        lpagery_root()->endpointHealthProbe()->probe();
    }

    /**
     * Schedule an immediate one-off probe (deferred a second, off the caller's critical path). WordPress
     * dedupes identical near-term events, so back-to-back triggers collapse to one probe.
     */
    public static function schedule_probe(): void
    {
        if (!wp_next_scheduled(self::PROBE_HOOK)) {
            wp_schedule_single_event(time() + 1, self::PROBE_HOOK);
        }
    }

    public static function ensure_scheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }

        // Activation/upgrade one-off, version-stamped like the rewrite flush ({@see \LPagery\service\image_endpoint\ImageEndpoint}):
        // a fresh install (option absent) and each upgrade (version changed) re-probe once, deferred to a
        // scheduled event so no request pays for a loopback fetch inline.
        if (get_option(self::VERSION_OPTION) !== self::PROBE_VERSION) {
            self::schedule_probe();
            update_option(self::VERSION_OPTION, self::PROBE_VERSION);
        }
    }
}
