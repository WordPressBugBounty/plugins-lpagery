<?php

namespace LPagery\service\image_endpoint;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\service\live_render\LivePagePurger;

/**
 * The **Endpoint Health** probe (ADR 0014): fetch one real Virtual Image URL from an existing live stub
 * over loopback HTTP and persist a strict site-wide verdict that feeds {@see \LPagery\service\live_render\VirtualImageAvailability}
 * — a failed verdict puts the whole site into **Source URL Fallback** until a later probe recovers it.
 *
 * Strict semantics (never a false degrade): only a definitive negative marks the endpoint unhealthy —
 * HTTP 404, or 200 with a non-image Content-Type. A 200 with an `image/*` Content-Type is healthy.
 * Anything else — a WP_Error (timeout, connection refused, blocked loopback), or an unexpected status
 * (5xx, 3xx) — is inconclusive and retains the previous verdict, so hosts that block loopback (the
 * WP-Cron problem) can never falsely fail. A passing probe recovers automatically, no admin action.
 *
 * Any verdict transition (either direction) purges live pages through {@see LivePagePurger} so cached
 * HTML converges to the new render shape instead of waiting for expiry; a non-transition probe writes
 * nothing and purges nothing. With no live stub carrying a Virtual Image Map there is nothing to fetch,
 * so the probe is a no-op — no HTTP request, verdict untouched.
 *
 * All FREE code (ADR 0009/0013): serving and its resilience must survive a lapsed license.
 */
class EndpointHealthProbe
{
    private GeneratedPageRepository $repository;
    private EndpointHealthTransport $transport;
    private EndpointHealthStore $store;
    private LivePagePurger $purger;

    public function __construct(
        GeneratedPageRepository $repository,
        EndpointHealthTransport $transport,
        EndpointHealthStore $store,
        LivePagePurger $purger
    ) {
        $this->repository = $repository;
        $this->transport = $transport;
        $this->store = $store;
        $this->purger = $purger;
    }

    /**
     * Run one probe cycle: pick a Virtual Image URL, fetch it, and reconcile the persisted verdict —
     * purging live pages only when the verdict actually flips.
     */
    public function probe(): void
    {
        $target = $this->pick_target_url();
        if ($target === null) {
            // No live stub carries a Virtual Image Map — nothing to probe, verdict untouched.
            return;
        }

        $verdict = $this->classify($this->transport->fetch($target));
        if ($verdict === null) {
            // Inconclusive (WP_Error or unexpected status): retain the previous verdict.
            return;
        }

        if ($verdict === $this->store->is_healthy()) {
            // Non-transition: no write, no purge.
            return;
        }

        $this->store->set_healthy($verdict);
        $this->purger->purge_all_live_pages();
    }

    /**
     * The Virtual Image URL to probe: the first Virtual Image Map entry of one live stub, or null when no
     * live stub carries a (well-formed) Virtual Image Map.
     */
    private function pick_target_url(): ?string
    {
        $stub = $this->repository->get_live_stub_with_virtual_image();
        if ($stub === null || !isset($stub->post_id, $stub->attachment_id_pairs)) {
            return null;
        }
        $pairs = maybe_unserialize($stub->attachment_id_pairs);
        if (!is_array($pairs)) {
            return null;
        }
        $entries = VirtualImageMap::entries($pairs);
        $first = $entries[0] ?? null;
        if ($first === null) {
            return null;
        }
        return VirtualImageUrl::full_url((int)$stub->post_id, $first['filename']);
    }

    /**
     * Strict verdict from a transport result: true (healthy), false (unhealthy), or null (inconclusive —
     * retain previous). Only 200+`image/*` is healthy; only 404 or 200+non-image is unhealthy.
     *
     * @param array{ok:bool, code:int, content_type:string} $result
     */
    private function classify(array $result): ?bool
    {
        if (!$result['ok']) {
            return null;
        }
        if ($result['code'] === 200) {
            return $this->is_image_content_type($result['content_type']) ? true : false;
        }
        if ($result['code'] === 404) {
            return false;
        }
        return null;
    }

    private function is_image_content_type(string $content_type): bool
    {
        return stripos($content_type, 'image/') === 0;
    }
}
