<?php

namespace LPagery\service\image_endpoint;

/**
 * The persisted **Endpoint Health** verdict (CONTEXT.md glossary, ADR 0014): the single source of
 * truth for whether the loopback probe last found the Image Endpoint able to serve real Virtual Image
 * URLs. Written only by {@see EndpointHealthProbe} and read by {@see \LPagery\service\live_render\VirtualImageAvailability}
 * to fold the verdict into the site-wide **Source URL Fallback** decision.
 *
 * The verdict lives in an autoload=false option (per-feature option pattern, see {@see \LPagery\service\live_render\LiveRenderTelemetry}):
 * it is out-of-band health state consulted only on the render path when live stubs exist, so it must not
 * ride on every request's autoload. It defaults to **healthy** when absent — strict semantics mark it
 * failed only on a definitive negative, so a never-probed site (or one whose loopback is blocked) must
 * never falsely degrade.
 */
class EndpointHealthStore
{
    public const OPTION = 'lpagery_endpoint_health_ok';

    /**
     * The current Endpoint Health verdict. True (healthy) unless a probe has persisted a definitive
     * failure — absence reads healthy so the render path never falsely enters Source URL Fallback.
     */
    public function is_healthy(): bool
    {
        return get_option(self::OPTION, '1') !== '0';
    }

    /**
     * Persist a probe verdict. Autoload=false keeps this out-of-band health state off the per-request
     * autoload path.
     */
    public function set_healthy(bool $healthy): void
    {
        update_option(self::OPTION, $healthy ? '1' : '0', false);
    }
}
