<?php

namespace LPagery\service\live_render;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Purge every live page so cached HTML converges to the current site-wide render shape. The shared seam
 * behind the two flips that change that shape for the whole site: the issue-228 Endpoint Health verdict
 * transition ({@see \LPagery\service\image_endpoint\EndpointHealthProbe}) and the ADR 0015 Virtual Image
 * URLs setting flip ({@see \LPagery\service\settings\SettingsController::saveSettings()}).
 *
 * Capped so a huge site degrades to a single full-site purge instead of tens of thousands of per-post
 * calls (via {@see LiveCachePurger::purge_posts()}); with no live page there is nothing to purge.
 *
 * FREE code (ADR 0009): serving and its resilience must survive a lapsed license.
 */
class LivePagePurger
{
    private GeneratedPageRepository $repository;
    private LiveCachePurger $purger;

    public function __construct(GeneratedPageRepository $repository, LiveCachePurger $purger)
    {
        $this->repository = $repository;
        $this->purger = $purger;
    }

    public function purge_all_live_pages(): void
    {
        $ids = $this->repository->get_all_live_stub_ids(LiveCachePurger::FULL_PURGE_THRESHOLD + 1);
        if (empty($ids)) {
            return;
        }
        $this->purger->purge_posts($ids);
    }
}
