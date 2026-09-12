<?php

namespace LPagery\service\view;

use LPagery\data\repository\PageMetaIndexRepository;
use LPagery\data\repository\ViewRepository;
use LPagery\model\view\ViewConfig;

/**
 * The View resolver: given a View config, the current Generated Page's placeholder data,
 * the current post id, and an optional explicit match value, it returns the ordered,
 * self-excluded set of matching sibling post ids from the View's source Process.
 *
 * This is the single shared seam behind both the [lpagery_view] shortcode render path and
 * (later) the builder's live preview. It serves matches from the sparse meta index when the
 * View's match key is an Indexed key that is ready (backfilled), and otherwise falls back to
 * scoped live deserialization of each page's serialized `data` blob. Both paths route their
 * value comparison through {@see ViewValueNormalizer} so they return identical results
 * (behavior invariance, ADR-0001).
 */
class ViewResolver
{
    private ViewRepository $viewRepository;
    private PageMetaIndexRepository $pageMetaIndexRepository;
    private ViewMetaIndexService $metaIndexService;

    public function __construct(ViewRepository $viewRepository, PageMetaIndexRepository $pageMetaIndexRepository, ViewMetaIndexService $metaIndexService)
    {
        $this->viewRepository = $viewRepository;
        $this->pageMetaIndexRepository = $pageMetaIndexRepository;
        $this->metaIndexService = $metaIndexService;
    }

    /**
     * Resolve the matching sibling post ids for a View.
     *
     * @param ViewConfig  $view              The View configuration.
     * @param array       $current_page_data The current Generated Page's deserialized placeholder data
     *                                        (key => value). Used to read the contextual match value and
     *                                        — together with $current_post_id — to exclude the current page.
     * @param int|null    $current_post_id   The current WordPress post id, excluded from results. Null on
     *                                        hand-built pages that are not Generated Pages.
     * @param string|null $explicit_value    Optional explicit match value (the shortcode `value=""` override).
     *
     * @return int[] Ordered list of matching post ids, with the current page excluded.
     */
    public function resolve(ViewConfig $view, array $current_page_data, ?int $current_post_id, ?string $explicit_value = null): array
    {
        // "All pages" selection mode ignores the Match key/value entirely and renders the whole
        // Page Set (capped), bypassing the meta index (ADR-0005). It still self-excludes and orders
        // through the shared path below.
        if ($view->selection() === ViewConfig::SELECTION_ALL) {
            return $this->resolve_all($view, $current_post_id);
        }

        $match_value = $this->determine_match_value($view, $current_page_data, $explicit_value);
        if ($match_value === null) {
            // No value to match on (missing key / empty). Visitors see nothing; the
            // shortcode handler is responsible for any admin diagnostic (slice 2).
            return array();
        }

        $normalized_target = $this->normalize_value($match_value);
        if ($normalized_target === "") {
            return array();
        }

        $can_use_index = $this->metaIndexService->is_indexed_key($view->process_id, $view->match_key)
            && $this->metaIndexService->is_key_ready($view->process_id, $view->match_key);

        // Both paths build the same {@see ViewSortable} candidates so ordering — applied next —
        // is identical regardless of where the match came from (ADR-0001 behavior invariance).
        $candidates = $can_use_index
            ? $this->resolve_from_index($view, $normalized_target)
            : $this->resolve_from_live($view, $normalized_target);

        // Mandatory self-exclusion: a page never appears in its own related list. Applied to
        // both paths uniformly so they stay identical.
        if ($current_post_id !== null) {
            $candidates = array_values(array_filter($candidates, static function (ViewSortable $candidate) use ($current_post_id) {
                return $candidate->post_id !== $current_post_id;
            }));
        }

        // Apply the ordering preset BEFORE the caller takes its limit/pagination window.
        return ViewOrdering::order($view, $candidates);
    }

    /**
     * All-pages path (ADR-0005): load the Process's Generated Pages capped at
     * {@see ViewConfig::ALL_PAGES_CAP} (the repository orders `created ASC` so the cap window is
     * stable), build the same sortable candidates as the match paths, self-exclude the current page,
     * and order through {@see ViewOrdering}. No Match value, no index — a single uniform path.
     *
     * @return int[] Ordered list of post ids, current page excluded.
     */
    private function resolve_all(ViewConfig $view, ?int $current_post_id): array
    {
        $rows = $this->viewRepository->rows_for_all_pages($view->process_id, ViewConfig::ALL_PAGES_CAP);

        $candidates = array();
        foreach ($rows as $row) {
            $data = $this->deserialize_data($row->data);
            $candidates[] = $this->to_sortable($view, (int)$row->post_id, $row, $data);
        }

        if ($current_post_id !== null) {
            $candidates = array_values(array_filter($candidates, static function (ViewSortable $candidate) use ($current_post_id) {
                return $candidate->post_id !== $current_post_id;
            }));
        }

        return ViewOrdering::order($view, $candidates);
    }

    /**
     * Index-backed path: an indexed SQL lookup keyed on (process, key, normalized value) gives
     * the matching ids; a focused read then loads the sort keys (title, created, menu_order,
     * data) for exactly those ids so ordering matches the live path.
     *
     * @return ViewSortable[]
     */
    private function resolve_from_index(ViewConfig $view, string $normalized_target): array
    {
        $post_ids = $this->pageMetaIndexRepository->get_post_ids_by_meta($view->process_id, $view->match_key, $normalized_target);
        if (empty($post_ids)) {
            return array();
        }

        $sort_rows = $this->viewRepository->sort_keys_for($post_ids);
        // Key the loaded sort rows by post id so we can preserve the matched set even if a row is
        // momentarily missing, and so output is independent of the sort-key read's row order.
        $by_id = array();
        foreach ($sort_rows as $row) {
            $by_id[(int)$row->post_id] = $row;
        }

        $candidates = array();
        foreach ($post_ids as $post_id) {
            $post_id = (int)$post_id;
            $row = $by_id[$post_id] ?? null;
            $data = $row !== null ? $this->deserialize_data($row->data) : array();
            $candidates[] = $this->to_sortable($view, $post_id, $row, $data);
        }
        return $candidates;
    }

    /**
     * Live-fallback path: scoped deserialization of the Process's page data, matching in PHP.
     * Used until the key's index is ready (and as the permanent fallback).
     *
     * @return ViewSortable[]
     */
    private function resolve_from_live(ViewConfig $view, string $normalized_target): array
    {
        $candidates = $this->viewRepository->rows_for_matching($view->process_id);

        $matching = array();
        foreach ($candidates as $candidate) {
            $candidate_data = $this->deserialize_data($candidate->data);
            if (!array_key_exists($view->match_key, $candidate_data)) {
                continue;
            }

            $candidate_value = $this->normalize_value((string)$candidate_data[$view->match_key]);
            if ($candidate_value === $normalized_target) {
                // No deduplication: one entry per matching page, since each is a distinct URL.
                $matching[] = $this->to_sortable($view, (int)$candidate->post_id, $candidate, $candidate_data);
            }
        }

        return $matching;
    }

    /**
     * Build a sortable candidate. The custom ordering field's value is resolved here (from the
     * page's data) so {@see ViewOrdering} stays a pure comparator. Title is read lazily from WP
     * (get_the_title) only when needed for alphabetical fallback ordering would otherwise require
     * a join; the live/index repository reads carry created + menu_order directly.
     *
     * @param object|null $row The repository row carrying created/menu_order, or null if unavailable.
     */
    private function to_sortable(ViewConfig $view, int $post_id, ?object $row, array $data): ViewSortable
    {
        $created = ($row !== null && isset($row->created)) ? (string)$row->created : "";
        $menu_order = ($row !== null && isset($row->menu_order)) ? (int)$row->menu_order : 0;
        $title = function_exists("get_the_title") ? (string)get_the_title($post_id) : "";

        $custom_field_value = null;
        if (isset($view->config["ordering"]) && $view->config["ordering"] === ViewOrdering::CUSTOM_FIELD) {
            $field = isset($view->config["ordering_field"]) ? (string)$view->config["ordering_field"] : "";
            if ($field !== "" && array_key_exists($field, $data)) {
                $value = $data[$field];
                $custom_field_value = $value === null ? null : (string)$value;
            }
        }

        return new ViewSortable($post_id, $title, $created, $menu_order, $data, $custom_field_value);
    }

    /**
     * Decide which value to match on: the explicit override if present, otherwise the match
     * key's value read from the current page's data. Returns null when neither yields a value.
     */
    public function determine_match_value(ViewConfig $view, array $current_page_data, ?string $explicit_value): ?string
    {
        if ($explicit_value !== null && $explicit_value !== "") {
            return $explicit_value;
        }
        if (array_key_exists($view->match_key, $current_page_data)) {
            $value = $current_page_data[$view->match_key];
            if ($value !== null && $value !== "") {
                return (string)$value;
            }
        }
        return null;
    }

    /**
     * Normalize a value before comparison via the shared {@see ViewValueNormalizer} so the
     * index-backed and live paths use one identical rule. Slice #161/#163 is exact; slice #162
     * makes it case-insensitive + trimmed by changing the normalizer alone.
     */
    protected function normalize_value(string $value): string
    {
        return ViewValueNormalizer::normalize($value);
    }

    /**
     * Coerce a repository row's `data` field to an array. The resolver reads
     * ({@see ViewRepository::rows_for_matching()} / {@see ViewRepository::rows_for_all_pages()} /
     * {@see ViewRepository::sort_keys_for()}) already return `data` unserialized, so this is a
     * cheap identity for the common array case; it only guards against a non-array value that
     * `maybe_unserialize` may yield for a row whose stored `data` was not a serialized array
     * (unchanged edge behavior: such a page contributes no placeholder keys).
     */
    private function deserialize_data($data): array
    {
        if (is_array($data)) {
            return $data;
        }
        if (!is_string($data) || $data === "") {
            return array();
        }
        $unserialized = maybe_unserialize($data);
        return is_array($unserialized) ? $unserialized : array();
    }
}
