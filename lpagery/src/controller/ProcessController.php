<?php

namespace LPagery\controller;

use LPagery\data\LPageryDao;
use LPagery\io\Mapper;
use LPagery\model\ProcessSheetSyncParams;
use LPagery\model\UpsertProcessParams;
use LPagery\service\delete\DeletePageService;
use LPagery\service\delete\DeleteProcessService;
use LPagery\service\delete\ResetLPageryService;
use LPagery\service\PageExportHandler;

/**
 * Controller for handling process-related operations
 */
class ProcessController
{
    private static $instance;
    private LPageryDao $lpageryDao;
    private Mapper $mapper;
    private ResetLPageryService $resetLPageryService;
    private PageExportHandler $pageExportHandler;

    /**
     * ProcessController constructor.
     *
     * @param LPageryDao $lpageryDao
     * @param Mapper $mapper
     * @param ResetLPageryService $resetLPageryService
     * @param PageExportHandler $pageExportHandler
     */
    public function __construct(
        LPageryDao $lpageryDao,
        Mapper $mapper,
        ResetLPageryService $resetLPageryService,
        PageExportHandler $pageExportHandler
    ) {
        $this->lpageryDao = $lpageryDao;
        $this->mapper = $mapper;
        $this->resetLPageryService = $resetLPageryService;
        $this->pageExportHandler = $pageExportHandler;
    }

    /**
     * Singleton pattern implementation
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            $lpageryDao = LPageryDao::get_instance();
            $deleteProcessService = DeleteProcessService::getInstance(
                $lpageryDao, 
                DeletePageService::getInstance($lpageryDao)
            );
            
            self::$instance = new self(
                $lpageryDao,
                Mapper::get_instance(),
                ResetLPageryService::getInstance($deleteProcessService),
                PageExportHandler::get_instance()
            );
        }
        return self::$instance;
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
        $lpagery_processes = $this->lpageryDao->lpagery_search_processes($post_id, $user_id, $search_term, $empty_filter, $managing_system);
        
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
        $process = $this->lpageryDao->lpagery_get_process_by_id($id);
        return $this->mapper->lpagery_map_process_update_details($process, []);
    }

    public function updateManagingSystem($id, string $managingSystem)
    {
        $process = $this->lpageryDao->lpagery_get_process_by_id($id);
        if ($process) {
            $this->lpageryDao->lpagery_update_process_managing_system($id, $managingSystem);
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
        $process = $this->lpageryDao->lpagery_get_process_by_id($upsertParams->getProcessId());

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
        
        $lpagery_process_id = $this->lpageryDao->lpagery_upsert_process(
            $upsertParams->getPostId(), 
            $upsertParams->getProcessId(), 
            $upsertParams->getPurpose(), 
            $data,
            $upsertParams->getGoogleSheetDataArray(), 
            $upsertParams->isSyncEnabled(), 
            $upsertParams->isIncludeParentAsIdentifier(),
            $upsertParams->getManagingsystem(),
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

        return [
            "taxonomy_terms" => $taxonomy_terms,
            "status" => $status,
            "parent_path" => $parent_path,
            "slug" => $slug
        ];
    }

    /**
     * Assign page set to current user
     *
     * @param int $process_id Process ID
     * @return array Result of operation
     */
    public function assignPageSetToMe(int $process_id): array
    {
        $process = $this->lpageryDao->lpagery_get_process_by_id($process_id);
        
        if (!$process) {
            throw new \Exception('Process not found');
        }

        $current_user_id = get_current_user_id();
        $this->lpageryDao->lpagery_update_process_user($process_id, $current_user_id);

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