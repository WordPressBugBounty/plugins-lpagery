<?php

namespace LPagery\service\save_page\update;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\model\GenerationRequest;
use LPagery\service\live_render\LiveCachePurger;
use LPagery\service\save_page\CreatePostDelegate;

/**
 * Materialize: turns a Live Mode Generated Page into a Classic one (ADR 0009/0010/0019).
 *
 * It re-runs the NORMAL classic generation for the page's stored Row Data through
 * {@see CreatePostDelegate}, so the exact same content + full meta copy + substituted values land on
 * the post as a fresh classic generation would. The page's identity (ID, slug, URL) is untouched:
 * only its content/meta and the `_lpagery_render_mode` marker change. The operation is idempotent
 * and safe to re-run, per page or per set in foreground batches.
 *
 * Materialize is available on every tier (ADR 0019), so this file is free code, carries no plan
 * check and holds free collaborators only. The other direction, Strip to Stub, is Extended-tier and
 * lives in its own premium service.
 */
class MaterializeService implements RenderModeConverter
{
    private GeneratedPageRepository $generatedPageRepository;
    private CreatePostDelegate $createPostDelegate;
    private LiveCachePurger $cachePurger;
    private RenderModeBatchSwitcher $batchSwitcher;

    public function __construct(GeneratedPageRepository $generatedPageRepository, CreatePostDelegate $createPostDelegate, LiveCachePurger $cachePurger, RenderModeBatchSwitcher $batchSwitcher)
    {
        $this->generatedPageRepository = $generatedPageRepository;
        $this->createPostDelegate = $createPostDelegate;
        $this->cachePurger = $cachePurger;
        $this->batchSwitcher = $batchSwitcher;
    }

    /**
     * The {@see RenderModeConverter} seam the background worker loopback and the browser controller
     * share. Only `classic` belongs to this service; `live` is the premium Strip to Stub service's
     * direction and is refused here so a mis-wired caller fails loudly instead of converting a page
     * the wrong way round.
     *
     * @throws \Exception
     */
    public function convert_page(int $post_id, string $direction): void
    {
        if ($direction !== 'classic') {
            throw new \Exception("MaterializeService only converts to classic, not to: $direction");
        }
        $this->materialize_page($post_id);
    }

    /**
     * Live → Classic for a single page: run the classic generation pipeline over the page's stored
     * Row Data so it becomes a fully self-contained classic post (content + meta), then the pipeline
     * itself flips `_lpagery_render_mode` to classic. No-op if the page is already classic.
     *
     * @throws \Exception
     */
    public function materialize_page(int $post_id): void
    {
        if ($post_id <= 0 || $this->current_render_mode($post_id) !== 'live') {
            return;
        }
        $process_post = $this->generatedPageRepository->get_process_post_data($post_id);
        if (empty($process_post) || empty($process_post->process_id)) {
            return;
        }

        // The classic pipeline regenerates this page FROM its template page, so a deleted template row
        // leaves nothing to generate from and the run would overwrite the stub with an empty page
        // (ADR 0017). Stop with a message the AJAX envelope can show instead. A trashed template still
        // has its row and its design, so it materializes as usual; a row-less tracking entry (template
        // id 0) has nothing to check and is left to the pipeline's own handling.
        $template_id = isset($process_post->source_id) ? (int)$process_post->source_id : 0;
        if ($template_id > 0 && empty(get_post($template_id))) {
            throw new \Exception("Page $post_id cannot be materialized to Classic Mode: its template page $template_id was deleted, so LPagery has no design left to generate the page from.");
        }

        // Reuse the classic create/update path: force a full content rewrite, overwrite any Manual
        // Change, and force classic generation for THIS page regardless of the set's config (which
        // stays live for a per-page materialize). The pipeline copies content + full meta and stamps
        // `_lpagery_render_mode = classic` via AdditionalDataSaver.
        $this->createPostDelegate->lpagery_create_post(GenerationRequest::for_materialization($post_id, (int)$process_post->process_id), array());

        $this->cachePurger->purge_posts(array($post_id));
    }

    /**
     * One batch of a set-level Materialize: convert up to $limit pages that still need it, then
     * report progress. Resumable, because the pages to convert are queried fresh each call.
     *
     * @return array{converted:int,remaining:int,total:int,done:bool,process_id:int,target_type:string,failed_post_ids:int[]}
     * @throws \Exception
     */
    public function switch_process(int $process_id, int $limit): array
    {
        return $this->batchSwitcher->run($this, $process_id, 'classic', $limit);
    }

    /**
     * The page's own Render Mode marker. wp-admin/AJAX runs with the meta proxy inactive, so this
     * reads the stub's physical value rather than a proxied template value. Absent ⇒ classic.
     */
    private function current_render_mode(int $post_id): string
    {
        $value = get_post_meta($post_id, '_lpagery_render_mode', true);
        return $value === 'live' ? 'live' : 'classic';
    }
}
