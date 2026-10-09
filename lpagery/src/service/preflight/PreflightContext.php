<?php

namespace LPagery\service\preflight;

/**
 * What every check of one Pre-flight Check run reads. The rows are resolved and the Template Page is
 * scanned on first use and then shared, so each happens once per run however many checks read
 * them. A failure while resolving them surfaces in each check that needs them, never in the checks
 * that don't.
 */
class PreflightContext
{
    private PreflightRequest $request;
    private string $post_type;
    private string $template_title;
    /** @var callable(): PreflightRow[] */
    private $resolve_rows;
    /** @var PreflightRow[]|null */
    private ?array $rows = null;
    /** @var callable(): TemplateScan */
    private $scan_template;
    private ?TemplateScan $template = null;

    /**
     * @param callable(): PreflightRow[] $resolve_rows
     * @param (callable(): TemplateScan)|null $scan_template null for an empty template
     */
    public function __construct(PreflightRequest $request, string $post_type, string $template_title, callable $resolve_rows, ?callable $scan_template = null)
    {
        $this->scan_template = $scan_template ?? function () {
            return new TemplateScan([]);
        };
        $this->request = $request;
        $this->post_type = $post_type;
        $this->template_title = $template_title;
        $this->resolve_rows = $resolve_rows;
    }

    public function request(): PreflightRequest
    {
        return $this->request;
    }

    public function post_type(): string
    {
        return $this->post_type;
    }

    public function template_title(): string
    {
        return $this->template_title;
    }

    /**
     * The rows the run would create pages from, ignored rows left out.
     *
     * @return PreflightRow[]
     */
    public function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = ($this->resolve_rows)();
        }
        return $this->rows;
    }

    /**
     * The Template Page's title, content, excerpt and post meta.
     */
    public function template(): TemplateScan
    {
        if ($this->template === null) {
            $this->template = ($this->scan_template)();
        }
        return $this->template;
    }
}
