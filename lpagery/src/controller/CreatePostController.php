<?php

namespace LPagery\controller;

use LPagery\data\repository\PageSetRepository;
use LPagery\data\repository\SyncQueueRepository;
use LPagery\model\GenerationRequest;
use LPagery\service\image_lookup\AttachmentSearchCache;
use LPagery\model\SyncQueueItem;
use LPagery\service\queue\BackgroundOperationInProgressException;
use LPagery\service\queue\QueueOperation;
use LPagery\service\save_page\CreatePostDelegate;
use LPagery\service\save_page\update\MaterializeService;
use LPagery\service\save_page\update\RenderModeStripper;
use LPagery\service\settings\SettingsController;
use LPagery\utils\ElementorCacheUtils;
use LPagery\utils\MemoryUtils;
use WP_Error;
use WP_REST_Request;
if (!defined('TEST_RUNNING')) {
    include_once(plugin_dir_path(__FILE__) . '/../utils/IncludeWordpressFiles.php');
}

class CreatePostController
{

    private CreatePostDelegate $createPostDelegate;
    private SettingsController $settingsController;
    private ?AttachmentSearchCache $cacheableAttachmentSearchService;
    private SyncQueueRepository $syncQueueRepository;
    private PageSetRepository $pageSetRepository;
    private MaterializeService $materializeService;
    private ?RenderModeStripper $stripToStubService;

    public function __construct(CreatePostDelegate $createPostDelegate, SettingsController $settingsController, ?AttachmentSearchCache $cacheableAttachmentSearchService, SyncQueueRepository $syncQueueRepository, PageSetRepository $pageSetRepository, MaterializeService $materializeService, ?RenderModeStripper $stripToStubService = null)
    {
        $this->createPostDelegate = $createPostDelegate;
        $this->settingsController = $settingsController;
        $this->cacheableAttachmentSearchService = $cacheableAttachmentSearchService;
        $this->syncQueueRepository = $syncQueueRepository;
        $this->pageSetRepository = $pageSetRepository;
        $this->materializeService = $materializeService;
        $this->stripToStubService = $stripToStubService;
    }


    public function lpagery_create_posts_queue(WP_REST_Request $request)
    {
        $secret_from_request = strval($request->get_param('secret'));
        $secret = strval(get_option('lpagery_queue_create_post_secret'));
        if (!hash_equals($secret, $secret_from_request)) {
            return new WP_Error('invalid_secret', 'Invalid secret ' . $secret_from_request, array('status' => 403));
        }

        $queue_item_id = $request->get_param("queue_item_id");
        if (!$queue_item_id) {
            return new WP_Error('invalid_queue_item_id', 'Invalid queue_item_id', array('status' => 400));
        }

        $queue_item = $this->syncQueueRepository->find_item_by_id((int)$queue_item_id);
        if ($queue_item === null) {
            return new WP_Error('queue_item_not_found', 'Queue item not found', array('status' => 404));
        }

        $creation_id = $queue_item->creation_id;
        $transient_key = "lpagery_$creation_id";
        $processed_slugs = get_transient($transient_key);
        if (!$processed_slugs) {
            $processed_slugs = [];
        } else {
            $processed_slugs = maybe_unserialize($processed_slugs);
        }
        $queue_transient_key = 'lpagery_queue_processing' . $queue_item->id;
        $currently_processing = get_transient($queue_transient_key);
        $already_processed = in_array($queue_item->slug, $processed_slugs);
        if ($already_processed || $currently_processing) {
            return array("success" => true,
                "slug" => $queue_item->slug);
        }

        set_transient($queue_transient_key, true, 60);

        // The finally guarantees the processing transient is released even when the conversion or the
        // create pipeline throws — otherwise a retry inside its 60s TTL would hit the currently_processing
        // early return above and report success without the item ever having been processed.
        try {
            // Background Render Mode switch (issue #220 Phase 10): a switch item carries a page id + target
            // mode instead of Row Data, and converts through the shared conversion service rather than the
            // create pipeline. Handled before the create-branch machinery (no google_sheet_data, no
            // GenerationRequest); the processing transient above still guards a double-dispatch.
            if ($queue_item->operation === QueueOperation::RENDER_MODE_SWITCH) {
                return $this->convert_render_mode_switch_item($queue_item);
            }

            $process_id = $queue_item->process_id;
            $process = $this->pageSetRepository->get_process_by_id($process_id);
            $force_update_content = $queue_item->force_update || $this->settingsController->isForceUpdateEnabled();
            $overwrite_manual_changes = $queue_item->overwrite_manual_changes || $this->settingsController->isOverwriteManualChangesEnabled();

            if ($queue_item->operation === QueueOperation::CREATE
                || $queue_item->operation === QueueOperation::UPDATE
                || $queue_item->operation === QueueOperation::REUPLOAD) {
                // Background create/update/re-upload (issue #220 Phase 4 + Phase 9): no google_sheet_data to
                // derive permissions from — mirror the browser create/update path (create + update both
                // allowed). The item carries the update-specific semantics stamped at expansion time.
                $request = GenerationRequest::for_background_queue_item($queue_item, $force_update_content, $overwrite_manual_changes);
            } else {
                $google_sheet_data = maybe_unserialize($process->google_sheet_data);
                $request = GenerationRequest::for_queue_item($queue_item, $google_sheet_data, $force_update_content, $overwrite_manual_changes);
            }
            $response = $this->createPostDelegate->lpagery_create_post($request, $processed_slugs);
            if ($creation_id && $response->slug && $response->mode !== "ignored") {
                $processed_slugs[] =  $response->createdPageCacheValue->value;
                set_transient($transient_key, $processed_slugs, 60);
            }

            $replaced_slug = $response->slug;

            return array("success" => true,
                "slug" => $replaced_slug);
        } finally {
            delete_transient($queue_transient_key);
        }
    }

    /**
     * Execute one background Render Mode switch item (issue #220 Phase 10): convert the item's page to its
     * target mode through the shared {@see \LPagery\service\save_page\update\RenderModeConverter} — the SAME seam the browser batch
     * switch drives, so both paths produce identical end states. The heavy live→classic materialization
     * runs the full classic pipeline, which is why switch items drain through this loopback per page for
     * the same memory isolation the create/update operations get. convert_page is per-page idempotent, so a
     * re-dispatched item (or a re-run after Cancel) is safe.
     *
     * @return array{success:bool,slug:string}|WP_Error
     */
    private function convert_render_mode_switch_item(SyncQueueItem $queue_item)
    {
        $data = is_array($queue_item->data) ? $queue_item->data : array();
        $post_id = (int)($data['post_id'] ?? 0);
        $target_mode = (($data['target_mode'] ?? '') === 'live') ? 'live' : 'classic';
        if ($post_id <= 0) {
            return new WP_Error('invalid_switch_item', 'Render Mode switch item is missing a post id', array('status' => 400));
        }

        // Route by direction (ADR 0019): Materialize is free and always wired, Strip to Stub is the
        // Extended-tier collaborator and is null in a free build. Only the background enqueue is
        // premium, so a live item can only exist where the stripper does, and a missing one is a
        // downgrade mid-run rather than a normal state.
        $converter = $target_mode === 'live' ? $this->stripToStubService : $this->materializeService;
        if ($converter === null) {
            return new WP_Error('conversion_unavailable', 'Stripping pages to Live Mode stubs is unavailable', array('status' => 403));
        }

        $converter->convert_page($post_id, $target_mode);

        return array("success" => true, "slug" => (string)$post_id);
    }


    function lpagery_create_posts_ajax($post_data)
    {
        // Per-set lock (issue #220 Phase 5): a browser-driven run on a Page Set that already has a
        // queued/running background operation is rejected with the in-progress state. Runs on sets
        // with no active operation (the normal case) proceed untouched.
        $process_id = isset($post_data["process_id"]) ? (int)$post_data["process_id"] : 0;
        if ($process_id > 0 && $this->syncQueueRepository->has_active_operation($process_id)) {
            throw new BackgroundOperationInProgressException();
        }

        $creation_id = $post_data["creation_id"];
        $is_last_page = filter_var($post_data["is_last_page"], FILTER_VALIDATE_BOOLEAN);
        $index = intval($post_data["index"]);
        if($index ==0 && $this->cacheableAttachmentSearchService) {
           $this->cacheableAttachmentSearchService->evict_cache();
        }


        $transient_key = "lpagery_$creation_id";
        $processed_slugs = get_transient($transient_key);
        if (!$processed_slugs) {
            $processed_slugs = [];
        } else {
            $processed_slugs = maybe_unserialize($processed_slugs);
        }

        $response = $this->createPostDelegate->lpagery_create_post(GenerationRequest::from_post_array($post_data), $processed_slugs);
        if ($creation_id && $response->slug &&  $response->createdPageCacheValue) {
            $processed_slugs[] = $response->createdPageCacheValue->value;
            if (!$is_last_page) {
                set_transient($transient_key, $processed_slugs, 60);
            }
        }

        $memory_usage = $this->getMemory_usage();
        $result_array = array("success" => true,
            "mode" => $response->mode,
            "used_memory" => $memory_usage,
            "slug" => $response->slug);

        if ($response->mode == "ignored") {
            $result_array["ignored_reason"] = $response->reason;
        }

        if ($response->mode == "created") {
            $result_array["created_reason"] = $response->reason;
        }

        if ($response->mode == "updated") {
            $result_array["updated_reason"] = $response->reason;
        }

        $this->handle_last_page($post_data, $is_last_page);

        if ($is_last_page) {
            delete_transient($transient_key);
        }

        return $result_array;

    }

    /**
     * @return array
     */
    private function getMemory_usage(): array
    {
        $memory_usage = array();
        try {
            $memory_usage = MemoryUtils::lpagery_get_memory_usage();
        } catch (\Throwable $e) {
            error_log($e->getMessage());
        }
        return $memory_usage;
    }




    /**
     * @param $post_data
     * @param bool $is_last_page
     * @return void
     */
    private function handle_last_page($post_data, bool $is_last_page): void
    {
        try {
            if ($is_last_page) {
                $this->syncQueueRepository->update_process_sync_status((int)$post_data['process_id'], "FINISHED");
                // Endpoint Health re-probe after a generation run completes (ADR 0014): the foreground
                // (browser-chunked) path's completion signal, mirroring the background worker's completion
                // drain. Guarded/deferred by schedule_probe(); the probe itself no-ops without live stubs.
                \LPagery\io\hooks\EndpointHealthHooks::schedule_probe();
                ElementorCacheUtils::clearCache();
            }
        } catch (\Throwable $e) {
            error_log($e->__toString());
        }
    }


}