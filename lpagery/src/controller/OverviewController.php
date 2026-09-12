<?php

namespace LPagery\controller;

use LPagery\service\overview\OverviewSnapshotService;

/**
 * Thin proxy for the Overview snapshot endpoint (issue #269). The Overview tab asks for the whole
 * snapshot in one request every time it opens, so the controller does nothing but wrap the service's
 * inventory in the shared success envelope. Reading the snapshot is not tier-gated: it reports the
 * site's own pages, which every tier is entitled to see. The AJAX handler capability-checks before
 * here.
 */
class OverviewController
{
    private OverviewSnapshotService $service;

    public function __construct(OverviewSnapshotService $service)
    {
        $this->service = $service;
    }

    /**
     * The Overview snapshot: page totals with their status and Render Mode splits, the Page Set
     * total, the health block, the twelve monthly buckets and the most recent Page Sets.
     *
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return array_merge(array('success' => true), $this->service->get_snapshot());
    }
}
