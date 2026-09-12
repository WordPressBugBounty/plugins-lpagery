<?php

namespace LPagery\service\delete;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\data\repository\PageSetRepository;
use LPagery\data\repository\SyncQueueRepository;
use LPagery\data\repository\ViewRepository;

class DeleteProcessService
{
    private ViewRepository $viewRepository;
    private GeneratedPageRepository $generatedPageRepository;
    private SyncQueueRepository $syncQueueRepository;
    private PageSetRepository $pageSetRepository;
    private DeletePageService $deletePageService;

    public function __construct(ViewRepository $viewRepository, GeneratedPageRepository $generatedPageRepository, SyncQueueRepository $syncQueueRepository, PageSetRepository $pageSetRepository, DeletePageService $deletePageService)
    {
        $this->viewRepository = $viewRepository;
        $this->generatedPageRepository = $generatedPageRepository;
        $this->syncQueueRepository = $syncQueueRepository;
        $this->pageSetRepository = $pageSetRepository;
        $this->deletePageService = $deletePageService;
    }

    /**
     * Remove a Page Set, optionally with the pages it generated, and report which pages LPagery kept
     * because live pages still render from them (ADR 0017). $force wipes those too, which is what
     * Reset LPagery asks for.
     *
     * @return array<int> the ids of the guarded Template Pages that were not deleted
     */
    public function deleteProcess(int $processId, bool $delete_posts, bool $force = false): array
    {
        $skipped = array();
        if ($delete_posts) {
            $posts = $this->generatedPageRepository->get_posts_by_process($processId);
            $post_ids = array_map(function($post) {
                return $post->id;
            }, $posts);
            if(!empty($post_ids)){
                $skipped = $this->deletePageService->deletePages($post_ids, $force);
            }
        }
        $this->deleteProcessCascade($processId);

        return $skipped;
    }

    /**
     * Remove a Page Set and everything owned by it, as four pure repository delegations preserving the
     * historical removal order — Views (ADR-0003, owned by the Process) → Generated Pages + their
     * sparse meta index rows (the single one-way GeneratedPageRepository -> PageMetaIndexRepository
     * seam) → the sync queue → the Page Set row itself.
     */
    private function deleteProcessCascade(int $processId): void
    {
        $this->viewRepository->delete_by_process($processId);
        $this->generatedPageRepository->delete_by_process($processId);
        $this->syncQueueRepository->delete_by_process($processId);
        $this->pageSetRepository->delete($processId);
    }

}
