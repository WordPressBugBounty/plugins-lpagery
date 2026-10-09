<?php

namespace LPagery\controller;

use LPagery\service\preflight\PreflightCheckService;
use LPagery\service\preflight\PreflightReport;
use LPagery\service\preflight\PreflightRequest;

/**
 * Controller for the Pre-flight Check, shared by the AJAX endpoint and the Suite REST route.
 */
class PreflightController
{
    private PreflightCheckService $preflightCheckService;

    public function __construct(PreflightCheckService $preflightCheckService)
    {
        $this->preflightCheckService = $preflightCheckService;
    }

    public function runPreflightCheck(PreflightRequest $request): PreflightReport
    {
        return $this->preflightCheckService->run($request);
    }
}
