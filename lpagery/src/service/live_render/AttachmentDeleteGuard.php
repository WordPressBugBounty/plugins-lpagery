<?php

namespace LPagery\service\live_render;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Delete guard for source attachments referenced by **Virtual Image Maps** (Phase 7, ADR 0014):
 * inform + purge, never block. The attachment edit screen surfaces {@see count_referencing_live_pages()}
 * as a "Used by N LPagery live pages" line, and on `delete_attachment`
 * {@see purge_pages_referencing()} drops the third-party full-page caches of the referencing live pages
 * through {@see LiveCachePurger}, so visitors stop receiving cached HTML pointing at a file that no
 * longer exists. The deletion itself is never cancelled — this only bounds the staleness afterwards.
 *
 * Free/serve-path safety: works regardless of license state. The referencing lookup is itself gated on
 * "any live stubs exist", so a site without live pages never scans.
 */
class AttachmentDeleteGuard
{
    private GeneratedPageRepository $repository;
    private LiveCachePurger $purger;

    public function __construct(GeneratedPageRepository $repository, LiveCachePurger $purger)
    {
        $this->repository = $repository;
        $this->purger = $purger;
    }

    /**
     * How many live pages' Virtual Image Maps reference $attachment_id — the blast radius shown on the
     * attachment edit screen. Zero when nothing references it (no line is rendered).
     */
    public function count_referencing_live_pages(int $attachment_id): int
    {
        return count($this->repository->get_live_stub_ids_referencing_attachment($attachment_id));
    }

    /**
     * Purge the live pages whose Virtual Image Maps reference $attachment_id, so their cached HTML stops
     * pointing at the just-deleted file. A no-op when nothing references it. Runs on `delete_attachment`,
     * i.e. mid-deletion — a throw here would abort the delete, so failures are swallowed and logged to
     * keep the "never blocks deletion" contract.
     */
    public function purge_pages_referencing(int $attachment_id): void
    {
        try {
            $post_ids = $this->repository->get_live_stub_ids_referencing_attachment($attachment_id);
            if (empty($post_ids)) {
                return;
            }
            $this->purger->purge_posts($post_ids);
        } catch (\Throwable $e) {
            error_log("LPagery: failed to purge live pages referencing deleted attachment $attachment_id: " . $e->getMessage());
        }
    }
}
