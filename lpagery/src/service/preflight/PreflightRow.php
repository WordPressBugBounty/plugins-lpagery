<?php

namespace LPagery\service\preflight;

/**
 * One source row as the generation would see it: the browser's `row_id`, the slug WordPress
 * would give the page, the parent it would be created under and its sanitized values.
 */
class PreflightRow
{
    public int $row_id;
    public string $slug;
    public int $parent_id;
    /** @var array<string, mixed> column key => sanitized value, only the columns the browser sent */
    public array $data;
    // The substituted `lpagery_parent` value when it names no post, so the configured parent is used.
    public ?string $unknown_parent;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(int $row_id, string $slug, int $parent_id, array $data = [], ?string $unknown_parent = null)
    {
        $this->row_id = $row_id;
        $this->slug = $slug;
        $this->parent_id = $parent_id;
        $this->data = $data;
        $this->unknown_parent = $unknown_parent;
    }
}
