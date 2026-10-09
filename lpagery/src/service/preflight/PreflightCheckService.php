<?php

namespace LPagery\service\preflight;

use LPagery\data\repository\PageSetRepository;
use LPagery\service\preflight\check\PreflightCheck;
use Throwable;

/**
 * The Pre-flight Check (CONTEXT.md): runs every check against the Page Set's configuration and its
 * source rows and collects their Findings into one report. It never blocks a run.
 *
 * Every check always runs. One that throws becomes a single "Couldn't check …" Problem naming it,
 * and the others still report, so there is no early abort.
 */
class PreflightCheckService
{
    public const CHECK_FAILED = 'check-failed';

    private PreflightRowResolver $rowResolver;
    private TemplateScanner $templateScanner;
    private PageSetRepository $pageSetRepository;
    /** @var PreflightCheck[] */
    private array $checks;

    /**
     * @param PreflightCheck[] $checks in report order
     */
    public function __construct(PreflightRowResolver $rowResolver, TemplateScanner $templateScanner, PageSetRepository $pageSetRepository, array $checks)
    {
        $this->rowResolver = $rowResolver;
        $this->templateScanner = $templateScanner;
        $this->pageSetRepository = $pageSetRepository;
        $this->checks = $checks;
    }

    public function run(PreflightRequest $request): PreflightReport
    {
        if ($request->slug === '' && $request->process_id > 0) {
            $request = $request->withSlug($this->stored_slug($request->process_id));
        }
        $post_type = (string)get_post_type($request->template_id);
        $template = get_post($request->template_id);
        $context = new PreflightContext($request, $post_type, $template ? (string)$template->post_title : '',
            function () use ($request, $post_type) {
                return $this->rowResolver->resolve($request, $post_type);
            },
            function () use ($request) {
                return $this->templateScanner->scan($request->template_id);
            });

        $findings = [];
        foreach ($this->checks as $check) {
            try {
                foreach ($check->run($context) as $finding) {
                    $findings[] = $finding;
                }
            } catch (Throwable $throwable) {
                $findings[] = Finding::pageSet(Finding::PROBLEM, self::CHECK_FAILED, [
                    'check' => $check->id(),
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
        return new PreflightReport($findings);
    }

    /**
     * The slug saved on the Page Set, for callers that check an existing Page Set without sending one.
     */
    private function stored_slug(int $process_id): string
    {
        $process = $this->pageSetRepository->get_process_by_id($process_id);
        $process_data = $process ? maybe_unserialize($process->data) : null;
        return (string)($process_data['slug'] ?? '');
    }
}
