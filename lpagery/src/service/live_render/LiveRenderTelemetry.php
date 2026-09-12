<?php

namespace LPagery\service\live_render;

use LPagery\service\TrackingPermissionService;

/**
 * Beta instrumentation for the Live Mode render path (Phase 9). PostHog capture is frontend-only in
 * this plugin, but a render error surfaces on an anonymous visitor request where no React app runs.
 * This service records such errors server-side into a small option that the dashboard drains and
 * reports to PostHog on load ({@see \LPagery\lpagery_get_live_render_errors}).
 *
 * Two guards keep it cheap and honest:
 * - Consent: nothing is recorded unless the PostHog tracking permission is granted (ADR: consent-aware
 *   telemetry, same setting the frontend obeys).
 * - Throttle: at most one event per Template Page per day via a transient, so a broken template can
 *   never flood the option regardless of traffic.
 *
 * Each event carries the identifiers that make a render error actionable: the template's builder, the
 * active SEO plugin, and the active full-page cache plugin.
 */
class LiveRenderTelemetry
{
    public const OPTION_EVENTS = 'lpagery_live_render_error_events';
    private const TRANSIENT_PREFIX = 'lpagery_live_render_err_';
    private const MAX_EVENTS = 20;

    private LiveBuilderSupport $builderSupport;
    private CachePluginDetector $detector;
    private TrackingPermissionService $trackingPermissions;

    public function __construct(LiveBuilderSupport $builderSupport, CachePluginDetector $detector, TrackingPermissionService $trackingPermissions)
    {
        $this->builderSupport = $builderSupport;
        $this->detector = $detector;
        $this->trackingPermissions = $trackingPermissions;
    }


    /**
     * Record one render error for a Template Page, subject to the consent and per-template-per-day
     * throttle guards. Never throws — it is called from the render path's failure branch.
     */
    public function record_render_error(int $template_id): void
    {
        if (!$this->consent_allowed()) {
            return;
        }
        $transient_key = self::TRANSIENT_PREFIX . $template_id;
        if (get_transient($transient_key)) {
            return;
        }
        set_transient($transient_key, 1, DAY_IN_SECONDS);

        $event = array(
            'template_id' => $template_id,
            'builder' => $this->builderSupport->detect_builder($template_id),
            'seo_plugin' => $this->detect_seo_plugin(),
            'cache_plugin' => $this->detect_cache_plugin(),
            'timestamp' => time(),
        );

        $events = get_option(self::OPTION_EVENTS, array());
        if (!is_array($events)) {
            $events = array();
        }
        $events[] = $event;
        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -self::MAX_EVENTS);
        }
        update_option(self::OPTION_EVENTS, $events, false);
    }

    /**
     * Read the accumulated render-error events and clear them, so the dashboard reports each event to
     * PostHog exactly once. Returns an empty array (without a write) when there is nothing to drain.
     *
     * @return array<int,array<string,mixed>>
     */
    public function consume_events(): array
    {
        $events = get_option(self::OPTION_EVENTS, array());
        if (!is_array($events) || empty($events)) {
            return array();
        }
        delete_option(self::OPTION_EVENTS);
        return $events;
    }

    private function consent_allowed(): bool
    {
        return $this->trackingPermissions->getPermissions()->getPosthog();
    }

    private function detect_seo_plugin(): string
    {
        if ($this->detector->constant_defined('WPSEO_VERSION') || $this->detector->class_available('WPSEO_Options')) {
            return 'yoast';
        }
        if ($this->detector->constant_defined('RANK_MATH_VERSION') || $this->detector->class_available('RankMath')) {
            return 'rankmath';
        }
        if ($this->detector->constant_defined('AIOSEO_VERSION') || $this->detector->function_available('aioseo')) {
            return 'aioseo';
        }
        return 'none';
    }

    private function detect_cache_plugin(): string
    {
        if ($this->detector->function_available('rocket_clean_domain') || $this->detector->constant_defined('WP_ROCKET_VERSION')) {
            return 'wp-rocket';
        }
        if ($this->detector->constant_defined('LSCWP_V')) {
            return 'litespeed';
        }
        if ($this->detector->function_available('w3tc_flush_all') || $this->detector->constant_defined('W3TC')) {
            return 'w3-total-cache';
        }
        if ($this->detector->function_available('wp_cache_clear_cache') || $this->detector->constant_defined('WPCACHEHOME')) {
            return 'wp-super-cache';
        }
        return 'none';
    }
}
