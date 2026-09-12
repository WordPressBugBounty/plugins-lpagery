<?php

namespace LPagery\service\live_render;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Degraded-state warning copy for the Virtual Image Endpoint (Phase 6, ADR 0014). When the site is
 * serving site-wide **Source URL Fallback** — the persisted **Endpoint Health** verdict is failed, or
 * pretty permalinks are off so the endpoint rewrite can't route — AND live stubs actually exist, LPagery
 * admin screens surface a non-dismissible warning naming the cause and its concrete fix. Mirrors
 * {@see LiveDeactivationWarning}: a pure service returning the sentence-or-null; the hook class renders it
 * and appends the help-center link (Virtual Image URLs → requirements) after the escaped sentence.
 *
 * Deliberately silent when healthy, when no live stubs exist (no page is serving fallback), when the
 * only cause is the `lpagery_virtual_images_enabled` filter — {@see VirtualImageAvailability::get_site_wide_degraded_reason()}
 * excludes that filter, since forcing it off is an intentional support snippet, not degradation to nag about
 * — and when the site-wide Virtual Image URLs setting is voluntarily off (ADR 0015, checked via
 * {@see VirtualImageAvailability::is_voluntarily_disabled()}): a chosen opt-out, not degradation, even if
 * permalinks/health would otherwise force Source URL Fallback.
 */
class VirtualImageDegradationWarning
{
    private VirtualImageAvailability $availability;
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(
        VirtualImageAvailability $availability,
        GeneratedPageRepository $generatedPageRepository
    ) {
        $this->availability = $availability;
        $this->generatedPageRepository = $generatedPageRepository;
    }

    /**
     * The warning sentence naming the Source URL Fallback cause and its fix, or null when there is
     * nothing to surface (healthy state, or no live stubs). Plain text; the caller escapes it.
     */
    public function get_warning(): ?string
    {
        // Voluntary opt-out (ADR 0015 §4): when the site-wide Virtual Image URLs setting is off, the
        // Source URL Fallback is chosen, not degraded — stay silent even if permalinks/health would
        // otherwise force it. This asymmetry is what makes a visible switch safe (vs ADR 0014's footgun).
        if ($this->availability->is_voluntarily_disabled()) {
            return null;
        }
        $reason = $this->availability->get_site_wide_degraded_reason();
        if ($reason === null) {
            return null;
        }
        if (!$this->generatedPageRepository->live_stubs_exist()) {
            return null;
        }

        if ($reason === VirtualImageAvailability::DEGRADED_PERMALINKS) {
            return __('LPagery: your Live Mode pages are serving Source URL Fallback for their images because pretty permalinks are disabled. Virtual Image URLs need a permalink structure to route. Enable pretty permalinks in Settings → Permalinks to restore them.', 'lpagery');
        }

        return __('LPagery: your Live Mode pages are serving Source URL Fallback for their images because the Endpoint Health check failed: your server is intercepting image URLs before WordPress can serve them.', 'lpagery');
    }
}
