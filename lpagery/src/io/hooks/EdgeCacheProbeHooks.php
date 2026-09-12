<?php

namespace LPagery\io\hooks;

use LPagery\service\image_endpoint\EdgeCacheProbe;
use LPagery\service\image_endpoint\EdgeCacheProbeResult;
use LPagery\service\settings\SettingsController;

/**
 * Registers the **Edge Cache Probe** scheduling triggers (issue #233): a version-stamped one-off event on
 * activation/upgrade that runs the probe off any request's critical path. Sibling of {@see EndpointHealthHooks},
 * but deliberately WITHOUT a recurring daily cron — the Edge Cache Probe is compute-once. An inconclusive run
 * reschedules a single retry ~a day out (up to the probe's own attempt cap); a conclusive verdict or a cap-out
 * schedules nothing, leaving no cron behind. An explicit settings save unschedules any pending event (see
 * {@see \LPagery\service\settings\SettingsController::saveSettings()}) so a manual choice ends probing at once.
 *
 * The scheduling is **self-healing**: a one-off cron event is a single point of failure (a fatal mid-run
 * consumes it, hosts and cleanup plugins delete cron rows, `wp_schedule_single_event()` can fail), and losing
 * it used to strand the site on "undetermined" forever. So while the probe still has work to do — setting
 * undecided, attempts under the cap, no run in flight — a missing event is re-armed on `init`.
 *
 * Registered unconditionally in FREE code (ADR 0009/0013): the Virtual Image URLs default the probe decides is
 * a serve-path concern that must survive a lapsed license.
 */
class EdgeCacheProbeHooks
{
    /** The one-off probe event — the sole scheduled hook (no recurring companion, unlike Endpoint Health). */
    public const PROBE_HOOK = 'lpagery_edge_cache_probe';

    private const VERSION_OPTION = 'lpagery_edge_cache_probe_version';
    /** Bump to force a one-off re-probe on the next request after an upgrade. */
    private const PROBE_VERSION = '1';

    public static function register(): void
    {
        add_action(self::PROBE_HOOK, [self::class, 'run_probe']);

        // Version-stamped activation/upgrade one-off + lost-event re-arm, evaluated once per request on init.
        add_action('init', [self::class, 'ensure_scheduled']);
    }

    /**
     * Run one probe cycle and, only when it came back inconclusive under the cap, queue a single retry a day
     * out. A conclusive verdict or a cap-out ({@see EdgeCacheProbeResult::CONCLUSIVE}/`DORMANT`) reschedules
     * nothing — the probe is done and no cron is left behind.
     */
    public static function run_probe(): void
    {
        $result = lpagery_root()->edgeCacheProbe()->run();
        if ($result === EdgeCacheProbeResult::RETRY) {
            self::schedule_probe(DAY_IN_SECONDS);
        }
    }

    public static function ensure_scheduled(): void
    {
        // Activation/upgrade one-off, version-stamped like the rewrite flush: a fresh install (option absent)
        // and each upgrade (version changed) re-probe once, deferred to a scheduled event so no request pays
        // for the front-door fetches inline.
        if (get_option(self::VERSION_OPTION) !== self::PROBE_VERSION) {
            self::schedule_probe(1);
            update_option(self::VERSION_OPTION, self::PROBE_VERSION);
            return;
        }
        self::reschedule_if_lost();
    }

    /**
     * Self-healing re-arm: when the probe still has work to do but its one-off event vanished (consumed by
     * a fatal mid-run, deleted by a cron-cleanup plugin, or a failed reschedule), queue a replacement. The
     * dormancy gates mirror {@see EdgeCacheProbe::run()} — a decided setting or a spent attempt cap means
     * the probe is done and nothing is re-armed — plus an in-flight-token gate, because this very request
     * may be one of a live run's probe fetches arriving while the event is momentarily consumed.
     */
    private static function reschedule_if_lost(): void
    {
        if (wp_next_scheduled(self::PROBE_HOOK)) {
            return;
        }
        if (get_option(SettingsController::OPTION_VIRTUAL_IMAGES_ENABLED, false) !== false) {
            return;
        }
        $store = lpagery_root()->edgeCacheProbeStore();
        if ($store->get_attempts() >= EdgeCacheProbe::MAX_ATTEMPTS) {
            return;
        }
        if ($store->get_token() !== null) {
            return;
        }
        // An hour out, not a second: a run that dies fatally never increments the attempt counter, so the
        // delay is what bounds a pathological crash-loop to one self-request an hour.
        self::schedule_probe(HOUR_IN_SECONDS);
    }

    /**
     * Schedule the one-off probe event $delay seconds out, unless one is already pending. WordPress dedupes
     * identical near-term events, so overlapping triggers collapse to one probe.
     */
    private static function schedule_probe(int $delay): void
    {
        if (!wp_next_scheduled(self::PROBE_HOOK)) {
            wp_schedule_single_event(time() + $delay, self::PROBE_HOOK);
        }
    }
}
