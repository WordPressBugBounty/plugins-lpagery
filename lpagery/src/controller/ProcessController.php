<?php

namespace LPagery\controller;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\data\repository\PageSetRepository;
use LPagery\io\Mapper;
use LPagery\model\ProcessSheetSyncParams;
use LPagery\model\UpsertProcessParams;
use LPagery\service\delete\ResetLPageryService;
use LPagery\service\live_render\LiveBuilderSupport;
use LPagery\service\PageExportHandler;
use LPagery\utils\Utils;

/**
 * Controller for handling process-related operations
 */
class ProcessController
{
    private Mapper $mapper;
    private ResetLPageryService $resetLPageryService;
    private PageExportHandler $pageExportHandler;
    private LiveBuilderSupport $liveBuilderSupport;
    private GeneratedPageRepository $generatedPageRepository;
    private PageSetRepository $pageSetRepository;

    /**
     * ProcessController constructor.
     *
     * @param Mapper $mapper
     * @param ResetLPageryService $resetLPageryService
     * @param PageExportHandler $pageExportHandler
     * @param LiveBuilderSupport $liveBuilderSupport
     * @param GeneratedPageRepository $generatedPageRepository
     * @param PageSetRepository $pageSetRepository
     */
    public function __construct(
        Mapper $mapper,
        ResetLPageryService $resetLPageryService,
        PageExportHandler $pageExportHandler,
        LiveBuilderSupport $liveBuilderSupport,
        GeneratedPageRepository $generatedPageRepository,
        PageSetRepository $pageSetRepository
    ) {
        $this->mapper = $mapper;
        $this->resetLPageryService = $resetLPageryService;
        $this->pageExportHandler = $pageExportHandler;
        $this->liveBuilderSupport = $liveBuilderSupport;
        $this->generatedPageRepository = $generatedPageRepository;
        $this->pageSetRepository = $pageSetRepository;
    }

    /**
     * Search processes
     *
     * @param int|null $post_id Post ID
     * @param int|null $user_id User ID
     * @param string $search_term Search term
     * @param string $empty_filter Empty filter
     * @return array Array of mapped processes
     */
    public function searchProcesses(?int $post_id = null, ?int $user_id = null, string $search_term = "", string $empty_filter = "", string $managing_system = null): array
    {
        $lpagery_processes = $this->pageSetRepository->search_processes($post_id, $user_id, $search_term, $empty_filter, $managing_system);
        
        if (is_null($lpagery_processes)) {
            return [];
        }
        
        return array_map([$this->mapper, 'lpagery_map_process_search'], $lpagery_processes);
    }

    /**
     * Get process details
     *
     * @param int $id Process ID
     * @return array Process details
     */
    public function getProcessDetails(int $id): array
    {
        $process = $this->pageSetRepository->get_process_by_id($id);
        return $this->mapper->lpagery_map_process_update_details($process, []);
    }

    public function updateManagingSystem($id, string $managingSystem)
    {
        $process = $this->pageSetRepository->get_process_by_id($id);
        if ($process) {
            $this->pageSetRepository->update_process_managing_system($id, $managingSystem);
        }
        return $process;
    }

    /**
     * Upsert process
     *
     * @param UpsertProcessParams $upsertParams Process parameters
     * @return array Result of operation
     */
    public function upsertProcess(UpsertProcessParams $upsertParams): array
    {
        $process = $this->pageSetRepository->get_process_by_id($upsertParams->getProcessId());

        if($process && $process->managing_system !== $upsertParams->getManagingsystem()) {
            throw new \Exception('Process source mismatch ' . $process->managing_system . ' != ' . $upsertParams->getManagingsystem());
        }
        $data = null;
        if($upsertParams->getPostId() && !get_post( $upsertParams->getPostId())) {
            throw new \Exception('Post not found');
        }
        if ($upsertParams->getData()) {
            $data = $this->extractProcessData($upsertParams->getData(), $process);
        }

        // Live Mode creation is Extended-tier only (ADR 0009) and needs a builder the render
        // pipeline supports (ADR 0007). Reject a live request instead of silently degrading to
        // classic, so the UI contract stays honest.
        if (is_array($data) && ($data['render_mode'] ?? 'classic') === 'live') {
            $this->assertLiveModeAllowed($upsertParams->getPostId());
        }

        // Validate-then-persist (Design D): when the slug template changed on an update, compute and
        // validate the per-page replaced_slug list HERE (throwing before any write on an invalid
        // slug), then hand the finished list to the persist-only DAO upsert. Persistence no longer
        // does substitution/validation.
        $slug_updates = $this->computeReplacedSlugUpdates($process, $upsertParams->getProcessId(), $data);

        $lpagery_process_id = $this->pageSetRepository->upsert(
            $upsertParams->getPostId(),
            $upsertParams->getProcessId(),
            $upsertParams->getPurpose(),
            $data,
            $upsertParams->getGoogleSheetDataArray(),
            $upsertParams->isSyncEnabled(),
            $upsertParams->isIncludeParentAsIdentifier(),
            $upsertParams->getManagingsystem(),
            $slug_updates,
        );

        if ($upsertParams->isGoogleSheetEnabled() && $upsertParams->isSyncEnabled()) {
            $status = $data["status"] ?? '-1';
            
            $syncParams = $upsertParams->createSyncParams(intval($lpagery_process_id), $status);
            if ($syncParams) {
                wp_schedule_single_event(time(), 'lpagery_start_sync_for_process', [$syncParams]);
            }
        }
        
        return [
            "success" => true,
            "process_id" => $lpagery_process_id
        ];
    }

    /**
     * Compute (and validate) the per-Generated-Page `replaced_slug` updates a Page Set upsert must
     * persist when its slug template changed (Design D — evicted from persistence). Returns:
     *   - null  when there is nothing to recompute: no config data, an insert (`$process_id <= 0`),
     *           no existing process, or an unchanged slug template. The persist-only upsert then
     *           leaves every `replaced_slug` untouched, exactly as before.
     *   - a list of `['id' => process_post_id, 'replaced_slug' => sanitized_slug]` when the slug
     *           template changed: each existing Generated Page's stored placeholder data is
     *           substituted into the new slug template and sanitized.
     *
     * Validate-then-persist: an invalid slug (one whose placeholder-aware sanitization still leaves
     * unreplaced `{…}` braces, i.e. a placeholder not present in that page's data) throws the exact
     * legacy "Slug … is not valid" exception BEFORE any write happens, so nothing is persisted —
     * behaviourally identical to the old in-transaction validation, which rolled everything back on
     * the same condition.
     *
     * @param object|null $process the already-fetched existing Page Set (or null on an insert)
     * @param array<string, mixed>|null $data the processed config data being persisted
     * @return array<int, array{id: int|string, replaced_slug: string}>|null
     */
    private function computeReplacedSlugUpdates(?object $process, int $process_id, ?array $data): ?array
    {
        if (!$data || $process_id <= 0 || !$process) {
            return null;
        }

        $old_slug = maybe_unserialize($process->data)["slug"] ?? null;
        $new_slug = $data["slug"];
        if ($old_slug === $new_slug) {
            return null;
        }

        $slug_updates = [];
        $process_posts = $this->generatedPageRepository->get_process_post_input_data($process_id);
        foreach ($process_posts as $process_post) {
            $params = lpagery_root()->inputParamProvider()->lpagery_get_input_params_without_images(maybe_unserialize($process_post->data));
            $slug = lpagery_root()->substitutionHandler()->lpagery_substitute_slug($params, $new_slug);
            $slug_with_braces = Utils::lpagery_sanitize_title_with_dashes($slug);
            $slug = sanitize_title($slug);
            if ($slug_with_braces !== $slug) {
                throw new \Exception("Slug $slug_with_braces is not valid. Please make sure to only use placeholders which are available in the current data. If you want to add new data to the slug, please make sure to update the content first.");
            }
            $slug_updates[] = ['id' => $process_post->id, 'replaced_slug' => $slug];
        }

        return $slug_updates;
    }

    /**
     * Enforces the Live Mode creation gate: Extended tier (ADR 0009) plus a builder the render
     * pipeline supports (ADR 0007). Throws a clear exception the AJAX layer surfaces to the user.
     *
     * @param int|null $template_id Template Page ID the set is being created from
     */
    private function assertLiveModeAllowed(?int $template_id): void
    {
        if (!lpagery_fs()->is_plan_or_trial('extended')) {
            throw new \Exception('Live Mode is an Extended plan feature. Upgrade to create Live Mode page sets.');
        }

        if (!$template_id || !$this->liveBuilderSupport->is_supported($template_id)) {
            throw new \Exception("The template's page builder is not supported by Live Mode yet. Please use Classic Mode for this template.");
        }
    }

    /**
     * Extract process data
     *
     * @param array $input_data Input data
     * @param object|null $process Process object
     * @return array Process data
     */
    public function extractProcessData(array $input_data, ?object $process): array
    {
        if (empty($input_data)) {
            return [];
        }
        
        $taxonomy_terms = [];
        $taxonomy_terms_in_request = $input_data['taxonomy_terms'] ?? [];
        
        if (!empty($taxonomy_terms_in_request)) {
            // Filter and sanitize taxonomy terms to allow only numeric values
            foreach ($taxonomy_terms_in_request as $taxonomy => $terms) {
                $taxonomy_terms[$taxonomy] = array_map('intval', array_filter($terms, 'is_numeric'));
            }
        }

        $parent_path = (int)$input_data['parent_path'];
        $slug = $input_data['slug'];
        $status = $input_data['status'];

        if (isset($process)) {
            if ($status == "-1") {
                $unserialized_data = maybe_unserialize($process->data);
                $status = $unserialized_data['status'] ?? get_post_status($process->post_id);
            }
        }

        $result = [
            "taxonomy_terms" => $taxonomy_terms,
            "status" => $status,
            "parent_path" => $parent_path,
            "slug" => $slug
        ];

        // Render Mode: absent or non-live ⇒ classic (the default), which is never
        // written so classic config blobs stay byte-for-byte identical.
        $render_mode = sanitize_text_field($input_data['render_mode'] ?? 'classic');
        if ($render_mode === 'live') {
            $result['render_mode'] = 'live';
        }

        return $result;
    }

    /**
     * Assign page set to current user
     *
     * @param int $process_id Process ID
     * @return array Result of operation
     */
    public function assignPageSetToMe(int $process_id): array
    {
        $process = $this->pageSetRepository->get_process_by_id($process_id);
        
        if (!$process) {
            throw new \Exception('Process not found');
        }

        $current_user_id = get_current_user_id();
        $this->pageSetRepository->update_process_user($process_id, $current_user_id);

        return [
            "success" => true,
            "process_id" => $process_id
        ];
    }

    /**
     * Export process as JSON
     *
     * @param int $process_id Process ID
     */
    public function exportProcessJson(int $process_id): void
    {
        $this->pageExportHandler->export($process_id);
    }

    /**
     * Reset all LPagery data
     *
     * @param bool $delete_pages Whether to delete pages
     */
    public function resetData(bool $delete_pages = false): void
    {
        $this->resetLPageryService->resetLPagery($delete_pages);
    }
} 