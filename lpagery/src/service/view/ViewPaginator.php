<?php

namespace LPagery\service\view;

/**
 * Pure pagination math for a View's rendered output (slice #165).
 *
 * The resolver returns the full ordered, self-excluded set of post ids; this helper turns that
 * set + a limit + the requested page number into the window of ids to render and the metadata a
 * numbered nav needs. Numbered, server-rendered pagination only — no "load more"/AJAX in v1.
 *
 * Design choice: the resolver stays whole-set (no limit/offset args). Slicing the page window
 * and rendering nav live in the renderer/controller layer, so ordering + matching invariants are
 * untouched and pagination is a thin, independently testable wrapper.
 */
class ViewPaginator
{
    /** Default limit when a View does not configure one. */
    const DEFAULT_LIMIT = 50;

    /** @var int[] the post ids to render for the current page (the window) */
    public array $page_ids;
    /** 1-based current page number, clamped into range. */
    public int $current_page;
    /** Total number of pages (>= 1). */
    public int $total_pages;
    /** Total number of matched ids across all pages. */
    public int $total;
    /** Effective per-page limit applied. */
    public int $limit;

    /**
     * @param int[] $all_ids       Full ordered, self-excluded set of post ids.
     * @param int   $limit         Per-page cap (<= 0 falls back to DEFAULT_LIMIT).
     * @param int   $requested_page 1-based requested page number (clamped to [1, total_pages]).
     */
    public function __construct(array $all_ids, int $limit, int $requested_page)
    {
        $this->total = count($all_ids);
        $this->limit = $limit > 0 ? $limit : self::DEFAULT_LIMIT;
        $this->total_pages = $this->total > 0 ? (int)ceil($this->total / $this->limit) : 1;

        $this->current_page = $requested_page;
        if ($this->current_page < 1) {
            $this->current_page = 1;
        }
        if ($this->current_page > $this->total_pages) {
            $this->current_page = $this->total_pages;
        }

        $offset = ($this->current_page - 1) * $this->limit;
        $this->page_ids = array_slice($all_ids, $offset, $this->limit);
    }

    /**
     * True when a numbered nav should be shown (matches exceed a single page).
     */
    public function has_pagination(): bool
    {
        return $this->total_pages > 1;
    }
}
