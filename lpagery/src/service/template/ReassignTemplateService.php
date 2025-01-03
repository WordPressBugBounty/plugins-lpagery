<?php

namespace LPagery\service\template;

use Exception;
use LPagery\data\LPageryDao;

class ReassignTemplateService
{
    private static ?ReassignTemplateService $instance = null;
    private LPageryDao $lpageryDao;

    public function __construct(LPageryDao $lpageryDao)
    {
        $this->lpageryDao = $lpageryDao;
    }

    public static function getInstance(LPageryDao $lpageryDao): ReassignTemplateService
    {
        if (self::$instance === null) {
            self::$instance = new ReassignTemplateService($lpageryDao);
        }
        return self::$instance;
    }

    /**
     * Reassigns a template to a process
     * 
     * @param int $processId The ID of the process to update
     * @param int $templateId The ID of the new template
     * @throws Exception If process or template is not found
     */
    public function reassignTemplate(int $processId, int $templateId): void
    {
        if (!$processId || !$templateId) {
            throw new Exception("Process ID and Template ID are required");
        }

        $process = $this->lpageryDao->lpagery_get_process_by_id($processId);
        if (!$process) {
            throw new Exception("Process not found");
        }

        $template_post = get_post($templateId);
        if (!$template_post) {
            throw new Exception("Template not found");
        }

        $this->lpageryDao->lpagery_update_process_template($processId, $templateId);
    }
} 