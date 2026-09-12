<?php

namespace LPagery\service\live_render;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Orphaned Live Page warning copy (ADR 0017 §4). A Live Mode page keeps only a Stub Post; its design
 * lives on the template page and is proxied in at render time, so a trashed or deleted template turns
 * every page of the set into an Orphaned Live Page that answers visitors with a 404. Nothing is written
 * to the stub, so the pages come back the moment the template does, and this warning is what tells the
 * user that on LPagery admin screens.
 *
 * Mirrors {@see VirtualImageDegradationWarning}: a pure service returning the sentence-or-null, escaped
 * and rendered by {@see \LPagery\io\hooks\LiveRenderHooks}. Silent when no live stubs exist at all (one
 * cheap EXISTS query gates the three-table orphan count) and when nothing is orphaned.
 */
class OrphanedLivePageWarning
{
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(GeneratedPageRepository $generatedPageRepository)
    {
        $this->generatedPageRepository = $generatedPageRepository;
    }

    /**
     * The warning sentence naming the number of Orphaned Live Pages and what brings them back, or null
     * when nothing is orphaned. Plain text; the caller escapes it.
     */
    public function get_warning(): ?string
    {
        if (!$this->generatedPageRepository->live_stubs_exist()) {
            return null;
        }

        $counts = $this->generatedPageRepository->count_orphaned_live_stubs();
        $trashed = (int)$counts['trashed'];
        $missing = (int)$counts['missing'];
        $total = $trashed + $missing;
        if ($total <= 0) {
            return null;
        }

        if ($missing > 0) {
            // One deleted template row is enough to make the promise false for part of the group, so the
            // harder case wins: the classic pipeline regenerates a page from its template, and a template
            // that is gone leaves nothing to generate from.
            return sprintf(
                /* translators: %d is the number of Live Mode pages whose template page no longer exists. */
                _n(
                    'LPagery found %d live page whose template page no longer exists, so it returns 404 for visitors. Convert to Classic cannot bring it back, because the design it renders lives on the template page. You can restore the template page from a backup, or delete the page set to remove the page.',
                    'LPagery found %d live pages whose template page no longer exists, so they return 404 for visitors. Convert to Classic cannot bring them back, because the design they render lives on the template page. You can restore the template page from a backup, or delete the page set to remove the pages.',
                    $total,
                    'lpagery'
                ),
                $total
            );
        }

        return sprintf(
            /* translators: %d is the number of Live Mode pages whose template page is in the trash. */
            _n(
                'LPagery found %d live page whose template page is in the trash, so it returns 404 for visitors. You can restore the template page to bring it back, or delete the page set to remove the page. Convert to Classic still works while the template page is in the trash.',
                'LPagery found %d live pages whose template page is in the trash, so they return 404 for visitors. You can restore the template page to bring them back, or delete the page set to remove the pages. Convert to Classic still works while the template page is in the trash.',
                $total,
                'lpagery'
            ),
            $total
        );
    }
}
