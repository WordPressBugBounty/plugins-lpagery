<?php

namespace LPagery\service\live_render;

use LPagery\service\image_endpoint\EndpointHealthStore;
use LPagery\service\settings\SettingsController;

/**
 * Thin availability/health seam for the render-time image map (mirrors {@see CachePluginDetector}):
 * it answers whether the Image Endpoint can serve Virtual Image URLs, so {@see LiveImageMap} consults
 * one collaborator instead of reading options/globals/filters directly.
 *
 * When this reports the endpoint unavailable, the virtual channel skips adding its replacement pairs
 * and the page renders with **Source URL Fallback** (CONTEXT.md): the source attachment URL — alt/title
 * still substituted by the text pass — is what serves. The Virtual Image Map stays persisted and the
 * Image Endpoint stays registered in every state, so recovery is render-time only.
 *
 * Site-wide triggers folded in here: the persisted **Virtual Image URLs** setting (ADR 0015, default
 * on) being switched off, the `lpagery_virtual_images_enabled` filter (default true) returning false,
 * pretty permalinks being off (empty permalink structure — the Image Endpoint needs a non-empty
 * permalink structure to route), and the persisted **Endpoint Health** verdict being failed (Phase 3 —
 * the loopback probe found the endpoint unable to serve; see {@see EndpointHealthStore}). The setting-off
 * case is voluntary, not degraded ({@see self::is_voluntarily_disabled()}), so it is excluded from the
 * degraded reason. The per-image source-file check ({@see self::source_file_exists()}) is the remaining,
 * per-image trigger.
 */
class VirtualImageAvailability
{
    /** Site-wide degraded reason: plain permalinks, so the Image Endpoint rewrite can't route. */
    public const DEGRADED_PERMALINKS = 'permalinks';

    /** Site-wide degraded reason: the persisted Endpoint Health verdict is failed. */
    public const DEGRADED_ENDPOINT_HEALTH = 'endpoint_health';

    private EndpointHealthStore $healthStore;
    private SettingsController $settingsController;

    public function __construct(EndpointHealthStore $healthStore, SettingsController $settingsController)
    {
        $this->healthStore = $healthStore;
        $this->settingsController = $settingsController;
    }

    /**
     * Whether Virtual Image URLs can be served site-wide. False triggers Source URL Fallback for the
     * whole request — from the Virtual Image URLs setting being off, the filter, plain permalinks, or a
     * failed Endpoint Health verdict (ADR 0015: virtual serving requires setting AND filter AND
     * permalinks AND health).
     */
    public function site_wide_enabled(): bool
    {
        // Voluntary site-wide opt-out (ADR 0015): the persisted Virtual Image URLs setting. Checked
        // first — cheapest, and off means the whole request renders Source URL Fallback by choice.
        if (!$this->settingsController->isVirtualImagesEnabled()) {
            return false;
        }
        if (!apply_filters('lpagery_virtual_images_enabled', true)) {
            return false;
        }
        if (!$this->pretty_permalinks_enabled()) {
            return false;
        }
        // Persisted Endpoint Health verdict (ADR 0014): a failed probe degrades the whole site to Source
        // URL Fallback until a later passing probe recovers it. Defaults healthy, so a never-probed site
        // is unaffected.
        return $this->healthStore->is_healthy();
    }

    /**
     * The site-wide **Source URL Fallback** cause to surface on LPagery admin screens (Phase 6), or
     * null when Virtual Image URLs can serve. Deliberately excludes the `lpagery_virtual_images_enabled`
     * filter: forcing that off is an intentional support snippet, not degradation to warn about. Plain
     * permalinks take precedence over a failed Endpoint Health verdict — the more common, self-serve fix.
     */
    public function get_site_wide_degraded_reason(): ?string
    {
        if (!$this->pretty_permalinks_enabled()) {
            return self::DEGRADED_PERMALINKS;
        }
        if (!$this->healthStore->is_healthy()) {
            return self::DEGRADED_ENDPOINT_HEALTH;
        }
        return null;
    }

    /**
     * Whether Virtual Image URLs are switched off on purpose via the site-wide setting (ADR 0015), as
     * opposed to environmentally degraded. The degradation warning ({@see VirtualImageDegradationWarning})
     * consults this to stay silent on a voluntary opt-out — even when permalinks or Endpoint Health would
     * otherwise force Source URL Fallback (voluntary ≠ degraded). Kept out of
     * {@see self::get_site_wide_degraded_reason()} so the setting is never surfaced as a degraded cause.
     */
    public function is_voluntarily_disabled(): bool
    {
        return !$this->settingsController->isVirtualImagesEnabled();
    }

    /**
     * Whether a single source attachment's file is present on disk. False triggers per-image Source URL
     * Fallback for just that image (offloaded media with "remove local files", or a deleted file): the
     * endpoint would 404 that Virtual Image URL, so the render skips remapping it and its source URL —
     * for offloaded media the media CDN URL — serves instead, while every other image on the page keeps
     * its Virtual Image URL. Mirrors the endpoint's own source-file gate ({@see ImageEndpoint}).
     */
    public function source_file_exists(int $source_id): bool
    {
        $file = get_attached_file($source_id);
        return is_string($file) && $file !== '' && file_exists($file);
    }

    /**
     * The Image Endpoint routes `/lpagery-img/…` through a rewrite rule, which only exists when the
     * site uses a non-empty permalink structure. Plain permalinks → Source URL Fallback site-wide.
     */
    private function pretty_permalinks_enabled(): bool
    {
        $structure = get_option('permalink_structure');
        return is_string($structure) && $structure !== '';
    }
}
