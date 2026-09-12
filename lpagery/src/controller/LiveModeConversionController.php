<?php

namespace LPagery\controller;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\data\repository\SyncQueueRepository;
use LPagery\service\queue\BackgroundOperationInProgressException;
use LPagery\service\save_page\update\MaterializeService;
use LPagery\service\save_page\update\RenderModeStripper;

/**
 * Thin proxy for the browser-driven Render Mode conversions, routing each request by direction
 * (ADR 0019):
 *
 *  - `classic` (Materialize) runs on every tier through the free {@see MaterializeService}, so a
 *    site whose plan lapsed can always take its Live Mode pages with it.
 *  - `live` (Strip to Stub) is Extended-tier. The collaborator is held by the free
 *    {@see RenderModeStripper} interface, is `null` in a free build, and the tier is re-checked here
 *    for the downgrade case, so both answer the same error.
 *
 * Both entry points are nonce- and capability-checked at the AJAX layer.
 */
class LiveModeConversionController
{
    private MaterializeService $materializeService;
    private SyncQueueRepository $syncQueueRepository;
    private GeneratedPageRepository $generatedPageRepository;
    private ?RenderModeStripper $stripToStubService;

    public function __construct(MaterializeService $materializeService, SyncQueueRepository $syncQueueRepository, GeneratedPageRepository $generatedPageRepository, ?RenderModeStripper $stripToStubService = null)
    {
        $this->materializeService = $materializeService;
        $this->syncQueueRepository = $syncQueueRepository;
        $this->generatedPageRepository = $generatedPageRepository;
        $this->stripToStubService = $stripToStubService;
    }

    /**
     * Per-page conversion (used by the admin interstitial's Materialize action, and reusable as a row
     * action). Returns the URL to send the user to on success — after materializing to Classic Mode
     * the page opens in the normal editor rather than the interstitial.
     *
     * @return array{post_id:int,direction:string,edit_url:string}
     * @throws \Exception
     */
    public function convert_page(int $post_id, string $direction): array
    {
        if ($post_id <= 0) {
            throw new \Exception('A valid post id is required.');
        }
        if ($direction !== 'live' && $direction !== 'classic') {
            throw new \Exception('Direction must be live or classic.');
        }
        $converter = $direction === 'live' ? $this->strip_to_stub_service() : $this->materializeService;

        // Reject a per-page convert while the page's set has a queued/running operation (issue #220):
        // the background switch drains through the SAME conversion seam, so a user-driven convert could
        // race the worker on this very page. Mirrors switch_process's guard below. Guarded at the
        // controller — the browser entry point — because the worker's own conversions flow through the
        // service while the set's queue items legitimately exist.
        $process_post = $this->generatedPageRepository->get_process_post_data($post_id);
        $process_id = !empty($process_post) && !empty($process_post->process_id) ? (int)$process_post->process_id : 0;
        if ($process_id > 0 && $this->syncQueueRepository->has_active_operation($process_id)) {
            throw new BackgroundOperationInProgressException();
        }

        $converter->convert_page($post_id, $direction);

        return array(
            'post_id' => $post_id,
            'direction' => $direction,
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
        );
    }

    /**
     * One batch of a set-level switch. The frontend calls this in a loop until `done`, driving a
     * progress bar from `total`/`remaining`; an interrupted run resumes on the next call.
     *
     * @return array{converted:int,remaining:int,total:int,done:bool,process_id:int,target_type:string,failed_post_ids:int[]}
     * @throws \Exception
     */
    public function switch_process(int $process_id, string $target_type, int $limit): array
    {
        if ($process_id <= 0) {
            throw new \Exception('A valid process id is required.');
        }
        if ($target_type !== 'live' && $target_type !== 'classic') {
            throw new \Exception('Target Render Mode must be live or classic.');
        }
        $stripper = $target_type === 'live' ? $this->strip_to_stub_service() : null;

        // Reject a browser-driven switch that conflicts with a background operation already holding the set
        // (issue #220 Phase 10): the same per-set lock that rejects a browser create surfaces the
        // in-progress state here, so a background switch/create/update and a browser switch can't race.
        if ($this->syncQueueRepository->has_active_operation($process_id)) {
            throw new BackgroundOperationInProgressException();
        }

        if ($stripper !== null) {
            return $stripper->switch_process($process_id, $limit);
        }
        return $this->materializeService->switch_process($process_id, $limit);
    }

    /**
     * The Extended-tier collaborator for the strip direction, or an error the AJAX envelope shows.
     * Non-suffixed compound form on purpose: this file survives into the free build, where a suffixed
     * check would take the refusal with it, and `is_plan_or_trial()` alone is true on a free build
     * whose site already carries a paid plan.
     *
     * @throws \Exception
     */
    private function strip_to_stub_service(): RenderModeStripper
    {
        if ($this->stripToStubService === null || !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial('extended'))) {
            throw new \Exception('Strip to Stub requires the Extended plan. You can materialize pages to Classic Mode on any plan.');
        }
        return $this->stripToStubService;
    }
}
