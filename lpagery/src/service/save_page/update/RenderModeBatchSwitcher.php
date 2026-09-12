<?php

namespace LPagery\service\save_page\update;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * The foreground batch loop behind a set-level Render Mode switch, in both directions (ADR 0019).
 * The frontend calls one batch per request until the result says `done`, driving a progress bar from
 * `total`/`remaining`.
 *
 * The loop itself is free code and free of any plan check: it converts whatever pages the injected
 * {@see RenderModeConverter} handles, so the free {@see MaterializeService} drives the Materialize
 * direction and the premium Strip to Stub service drives the other one. Whether the caller is
 * allowed to strip is decided at the controller, not here.
 */
class RenderModeBatchSwitcher
{
    /** Batch bounds for set-level switching: one AJAX call converts at most MAX_BATCH_SIZE pages. */
    public const DEFAULT_BATCH_SIZE = 10;
    public const MAX_BATCH_SIZE = 50;

    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(GeneratedPageRepository $generatedPageRepository)
    {
        $this->generatedPageRepository = $generatedPageRepository;
    }

    /**
     * One batch: convert up to $limit pages that still need it, then report progress. Resumable —
     * because the pages to convert are queried fresh each call, an interrupted run continues on the
     * next call. When no pages remain, the set's config-blob Render Mode is flipped so new
     * generations follow the new mode.
     *
     * @return array{converted:int,remaining:int,total:int,done:bool,process_id:int,target_type:string,failed_post_ids:int[]}
     * @throws \Exception
     */
    public function run(RenderModeConverter $converter, int $process_id, string $target_type, int $limit): array
    {
        $target_type = $target_type === 'live' ? 'live' : 'classic';
        // Floor AND cap: each page conversion runs the full classic pipeline synchronously, so an
        // oversized client-supplied limit would turn one AJAX call into an unbounded batch.
        $limit = $limit > 0 ? min($limit, self::MAX_BATCH_SIZE) : self::DEFAULT_BATCH_SIZE;

        $post_ids = $this->generatedPageRepository->get_process_post_ids_needing_conversion($process_id, $target_type, $limit);
        $converted = 0;
        $failed_post_ids = array();
        $last_error = null;
        foreach ($post_ids as $id) {
            // Isolate per-page failures: one broken page must not abort the batch — and because the
            // batch re-queries pages still needing conversion, an uncaught throw would re-select the
            // same page every call and wedge the whole set switch.
            try {
                $converter->convert_page((int)$id, $target_type);
                $converted++;
            } catch (\Throwable $e) {
                $failed_post_ids[] = (int)$id;
                $last_error = $e;
                error_log("LPagery render mode switch failed for post $id: " . $e->getMessage());
            }
        }
        if ($converted === 0 && !empty($failed_post_ids)) {
            // No progress at all: surface the failure instead of letting the client loop forever on
            // a batch that can never shrink.
            throw new \Exception('Converting pages ' . implode(', ', $failed_post_ids) . ' failed: '
                . ($last_error !== null ? $last_error->getMessage() : 'unknown error'));
        }

        $remaining = $this->generatedPageRepository->count_process_posts_needing_conversion($process_id, $target_type);
        $done = $remaining === 0;
        if ($done) {
            $this->generatedPageRepository->update_process_render_mode($process_id, $target_type);
        }

        return array(
            'converted' => $converted,
            'remaining' => $remaining,
            'total' => $this->generatedPageRepository->count_process_posts($process_id),
            'done' => $done,
            'process_id' => $process_id,
            'target_type' => $target_type,
            'failed_post_ids' => $failed_post_ids,
        );
    }
}
