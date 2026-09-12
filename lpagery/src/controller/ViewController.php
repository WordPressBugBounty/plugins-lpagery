<?php

namespace LPagery\controller;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\data\repository\PageMetaIndexRepository;
use LPagery\data\repository\ViewRepository;
use LPagery\io\Mapper;
use LPagery\model\view\ViewConfig;
use LPagery\service\view\ViewIndexBackfillWorker;
use LPagery\service\view\ViewMetaIndexService;
use LPagery\service\view\ViewPaginator;
use LPagery\service\view\ViewRenderer;
use LPagery\service\view\ViewResolver;
use LPagery\utils\Utils;

/**
 * Controller for related-pages Views.
 *
 * The render path (resolve + render an existing View) is reachable from free code so saved
 * Views keep working after a license downgrade (ADR-0002). View creation/editing is gated to
 * Pro/Extended at the AJAX endpoint layer, not here.
 */
class ViewController
{
    private ViewRepository $viewRepository;
    private PageMetaIndexRepository $pageMetaIndexRepository;
    private ViewResolver $viewResolver;
    private ViewRenderer $viewRenderer;
    private ViewIndexBackfillWorker $viewIndexBackfillWorker;
    private ViewMetaIndexService $metaIndexService;
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(ViewRepository $viewRepository, PageMetaIndexRepository $pageMetaIndexRepository, ViewResolver $viewResolver, ViewRenderer $viewRenderer, ViewIndexBackfillWorker $viewIndexBackfillWorker, ViewMetaIndexService $metaIndexService, GeneratedPageRepository $generatedPageRepository)
    {
        $this->viewRepository = $viewRepository;
        $this->pageMetaIndexRepository = $pageMetaIndexRepository;
        $this->viewResolver = $viewResolver;
        $this->viewRenderer = $viewRenderer;
        $this->viewIndexBackfillWorker = $viewIndexBackfillWorker;
        $this->metaIndexService = $metaIndexService;
        $this->generatedPageRepository = $generatedPageRepository;
    }

    /**
     * Render a saved View for the current page. Returns the rendered HTML, or an empty string
     * when the View does not exist or there is nothing to show.
     *
     * When the match key has no value on the current page (no contextual value and no explicit
     * override), visitors see nothing, but logged-in admins who can manage options get an inline
     * diagnostic so a misconfiguration (typo / wrong page type) is debuggable. A value that *is*
     * present but matches zero siblings is a legitimate empty result and stays silent.
     *
     * @param int         $view_id         The View to render (from [lpagery_view id="N"]).
     * @param int|null    $current_post_id The current WordPress post id (for contextual match value + self-exclusion).
     * @param string|null $explicit_value  Optional explicit match value override (the shortcode `value=""`).
     * @param int         $page            1-based pagination page number (from the per-View page request param).
     * @param string      $base_url        Current page URL to preserve in numbered pagination links.
     */
    public function renderView(int $view_id, ?int $current_post_id, ?string $explicit_value = null, int $page = 1, string $base_url = ""): string
    {
        $view = $this->loadView($view_id);
        if ($view === null) {
            return "";
        }

        // "All pages" selection mode ignores the Match key entirely (ADR-0005): no contextual match
        // value, no missing-value diagnostic. It renders the whole capped Page Set and warns the
        // admin (only) when the set exceeds the cap.
        if ($view->selection() === ViewConfig::SELECTION_ALL) {
            return $this->renderAllPages($view, $current_post_id, $page, $base_url);
        }

        $current_page_data = $this->getCurrentPageData($current_post_id);

        // Distinguish "no match value at all" (admin diagnostic) from "value present but no
        // siblings matched" (silent empty). The explicit override beats the contextual value.
        $match_value = $this->viewResolver->determine_match_value($view, $current_page_data, $explicit_value);
        if ($match_value === null) {
            return $this->renderMissingValueDiagnostic($view->match_key);
        }

        // Resolver returns the full ordered, self-excluded set; the controller takes the page
        // window here so matching/ordering invariants stay isolated from pagination.
        $post_ids = $this->viewResolver->resolve($view, $current_page_data, $current_post_id, $explicit_value);

        $limit = $this->resolveLimit($view, count($post_ids));
        $paginator = new ViewPaginator($post_ids, $limit, $page);
        $page_param = self::pageParamFor($view_id);

        return $this->viewRenderer->render($view, $paginator, $base_url, $page_param);
    }

    /**
     * The page request param for a View, namespaced by id so multiple Views on one page paginate
     * independently (e.g. "lpagery_view_page_3").
     */
    public static function pageParamFor(int $view_id): string
    {
        return "lpagery_view_page_" . $view_id;
    }

    /**
     * The effective per-page limit fed to the paginator.
     *
     * "Show all" (pagination=none) renders every match with no nav, so it must ignore the
     * configured per-page limit entirely — otherwise the limit silently truncates the set and the
     * dropped items become unreachable (there is no nav to page to). We express that by handing the
     * paginator a window large enough to cover the whole set, collapsing it to a single page.
     *
     * For numbered pagination, the limit is the page size: read defensively from config with a
     * sensible default (~50); non-positive/non-numeric falls back to the default.
     */
    private function resolveLimit(ViewConfig $view, int $match_count): int
    {
        if ($view->pagination() === ViewConfig::PAGINATION_NONE) {
            // Uncapped: cover the whole set in one page (>=1 so an empty set is still valid).
            return max(1, $match_count);
        }
        if (isset($view->config["limit"]) && is_numeric($view->config["limit"])) {
            $limit = (int)$view->config["limit"];
            if ($limit > 0) {
                return $limit;
            }
        }
        return ViewPaginator::DEFAULT_LIMIT;
    }

    /**
     * Render an "All pages" View: the whole Page Set capped at ViewConfig::ALL_PAGES_CAP, ordered
     * and self-excluded by the resolver, then paginated. Prepends an admin-only diagnostic when the
     * Page Set is larger than the cap so the editor knows the list is truncated (ADR-0005).
     */
    private function renderAllPages(ViewConfig $view, ?int $current_post_id, int $page, string $base_url): string
    {
        // All-pages resolution needs no current-page data (there is no Match value); pass an empty
        // data array. The current post id is still forwarded so a member page self-excludes.
        $post_ids = $this->viewResolver->resolve($view, array(), $current_post_id, null);

        $limit = $this->resolveLimit($view, count($post_ids));
        $paginator = new ViewPaginator($post_ids, $limit, $page);
        $page_param = self::pageParamFor($view->id);

        $html = $this->viewRenderer->render($view, $paginator, $base_url, $page_param);
        return $this->renderOverCapDiagnostic($view) . $html;
    }

    /**
     * Admin-only inline diagnostic shown when an "All pages" View's Page Set exceeds the cap, so the
     * editor learns the list is truncated to the first ALL_PAGES_CAP pages. Visitors see a clean
     * capped list with no notice. Mirrors {@see renderMissingValueDiagnostic}'s visibility gate.
     */
    private function renderOverCapDiagnostic(ViewConfig $view): string
    {
        if (!is_user_logged_in() || !current_user_can("manage_options")) {
            return "";
        }
        $total = $this->viewRepository->count_for_resolver($view->process_id);
        if ($total <= ViewConfig::ALL_PAGES_CAP) {
            return "";
        }
        $message = sprintf(
            /* translators: %1$d = total pages in the set, %2$d = the All-pages cap. */
            __("LPagery View: this page set has %1\$d pages; “All pages” mode shows the first %2\$d. Only you (an admin) can see this notice.", "lpagery"),
            $total,
            ViewConfig::ALL_PAGES_CAP
        );
        return "<div class='lpagery_view_admin_notice' style='margin:8px 0;padding:10px 12px;"
            . "border:1px solid #f0c36d;border-radius:6px;background:#fff8e5;color:#7a5b00;"
            . "font-size:13px;line-height:1.4'>" . esc_html($message) . "</div>";
    }

    /**
     * Admin-only inline diagnostic when the match key has no value on the current page. Visitors
     * get an empty string; only logged-in users who can manage options see a VISIBLE notice. It is
     * deliberately visible (not an HTML comment) so the common "I pasted the shortcode on a page
     * LPagery didn't generate and nothing shows" confusion is self-explaining: it names the missing
     * key and points at the value="…" fix. Visitors never see it.
     */
    private function renderMissingValueDiagnostic(string $match_key): string
    {
        if (!is_user_logged_in() || !current_user_can("manage_options")) {
            return "";
        }
        $key = esc_html($match_key);
        $message = sprintf(
            /* translators: %s = the View's match key (a placeholder column name). */
            __("LPagery View: no value for “%s” on this page. This page isn't a generated page in this set, so add value=\"…\" to the shortcode to set the match value yourself. Only you (an admin) can see this notice.", "lpagery"),
            $key
        );
        // The translatable sentence stays pure text; the help-center link (Views → "Using a View on a
        // page LPagery didn't generate") is appended after it with the URL outside the string.
        $help_link = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" data-testid="views-missing-value-help-link">%s</a>',
            esc_url('https://intercom.help/lpagery/en/articles/15899821#h_views_value'),
            esc_html__('Learn more about using a View on other pages', 'lpagery')
        );
        return "<div class='lpagery_view_admin_notice' style='margin:8px 0;padding:10px 12px;"
            . "border:1px solid #f0c36d;border-radius:6px;background:#fff8e5;color:#7a5b00;"
            . "font-size:13px;line-height:1.4'>" . $message . " " . $help_link . "</div>";
    }

    /**
     * Persist a new View. Accepts the full builder config (slot mappings, ordering, limit) via
     * the optional $config array; an empty array preserves the slice-1 minimal create behaviour.
     * The premium gate lives at the AJAX endpoint (ADR-0002), not in this method.
     *
     * @param array $config Optional rich config: { slots, ordering, ordering_field, limit }.
     * @return int the new View id
     */
    public function createView(int $process_id, string $name, string $match_key, string $mode = "list", array $config = array()): int
    {
        $view_id = $this->viewRepository->create($process_id, $name, $match_key, $mode, $config);

        // If this View introduces a newly-Indexed key for the Process, enqueue a chunked
        // backfill to populate that key across the Process's pages (ADR-0001, #166). This does
        // NOT block create: the View is usable immediately and reports an "indexing" state until
        // the job drains, during which the resolver falls back to live deserialization. enqueue()
        // is a no-op when the key is already ready or already queued, so it is safe to call on
        // every create.
        $this->viewIndexBackfillWorker->enqueue($process_id, $match_key);

        return $view_id;
    }

    /**
     * Update an existing View's match key, mode, and config. If the edit changes the match key
     * to one that becomes a newly-Indexed key for the Process, a chunked backfill is enqueued
     * (same as createView). The premium gate lives at the AJAX endpoint (ADR-0002), not here.
     *
     * @param array $config The full builder config: { slots, ordering, ordering_field, limit }.
     */
    public function updateView(int $view_id, string $name, string $match_key, string $mode, array $config): void
    {
        $existing = $this->loadView($view_id);
        if ($existing === null) {
            throw new \Exception("View $view_id does not exist.");
        }

        $this->viewRepository->update($view_id, $name, $match_key, $mode, $config);

        // The match key may now point at a placeholder column that has no index yet; enqueue the
        // backfill so the resolver can move off the live fallback. enqueue() is a no-op when the
        // key is already ready/queued, so calling it on every update is safe even if the key
        // didn't change.
        $this->viewIndexBackfillWorker->enqueue($existing->process_id, $match_key);
    }

    /**
     * Delete a View. Does the safe minimum: removes the View row. If that was the last View
     * using its match key for the Process, the key is no longer an Indexed key, so its index
     * readiness flag is cleared (the sparse index for that key is allowed to go stale — it is
     * only consulted while at least one View uses the key, and is rebuilt by backfill if the key
     * is re-introduced). The meta rows themselves are left in place (harmless, unreferenced) to
     * keep delete fast and avoid scanning a 100k-page Process synchronously.
     */
    public function deleteView(int $view_id): void
    {
        $view = $this->loadView($view_id);
        if ($view === null) {
            return;
        }

        $this->viewRepository->delete($view_id);

        // After deletion, is the match key still used by any of the Process's remaining Views?
        // An "all" selection View stores match_key = "" and indexes nothing (ADR-0005), so it has
        // no readiness flag to clear — skip it instead of writing meaningless state for "".
        $remaining_keys = $this->pageMetaIndexRepository->get_indexed_keys_for_process($view->process_id);
        if ($view->match_key !== "" && !in_array($view->match_key, $remaining_keys, true)) {
            $this->metaIndexService->set_key_ready($view->process_id, $view->match_key, false);
        }
    }

    /**
     * Render a DRAFT (unsaved) View for the builder's live preview. Builds a transient ViewConfig
     * from $draft_config and runs the SAME resolve + render path the front-end shortcode uses, so
     * preview output is identical to published output (acceptance criterion). Nothing is persisted.
     *
     * The match value comes from a chosen sample Generated Page (its data + self-exclusion) or a
     * typed explicit value, mirroring renderView's contextual/explicit resolution.
     *
     * @param array       $draft_config   { process_id, match_key, mode, slots?, ordering?, ordering_field?, limit? }
     * @param int|null    $sample_post_id A sample Generated Page id whose data seeds the contextual match value.
     * @param string|null $explicit_value A typed match value override (used when no sample page is chosen).
     */
    /**
     * @return array{html: string, total_pages: int, current_page: int}
     */
    public function previewView(array $draft_config, ?int $sample_post_id, ?string $explicit_value = null, int $page = 1): array
    {
        $process_id = (int)($draft_config["process_id"] ?? 0);
        $match_key = (string)($draft_config["match_key"] ?? "");
        $mode = (string)($draft_config["mode"] ?? ViewConfig::MODE_LIST);
        if (!in_array($mode, array(ViewConfig::MODE_LIST, ViewConfig::MODE_GRID), true)) {
            $mode = ViewConfig::MODE_LIST;
        }

        $config = array();
        if (isset($draft_config["slots"]) && is_array($draft_config["slots"])) {
            $config["slots"] = $draft_config["slots"];
        }
        if (isset($draft_config["ordering"]) && $draft_config["ordering"] !== "") {
            $config["ordering"] = (string)$draft_config["ordering"];
        }
        if (isset($draft_config["ordering_field"]) && $draft_config["ordering_field"] !== "") {
            $config["ordering_field"] = (string)$draft_config["ordering_field"];
        }
        if (isset($draft_config["limit"]) && is_numeric($draft_config["limit"])) {
            $config["limit"] = (int)$draft_config["limit"];
        }

        // Display + appearance + footer enums: pass the draft values straight through. ViewConfig's
        // accessors re-validate each against its whitelist (default on anything unknown), so the
        // preview is both safe and faithful to what the saved View will render — no sanitizer call
        // needed here. link_target/label_case are render-time label/anchor transforms; the rest are
        // the grid look-and-feel + footer knobs.
        $passthrough = array(
            "selection",
            "link_target", "label_case",
            "columns", "border", "shadow", "radius", "spacing", "align", "pagination",
        );
        foreach ($passthrough as $key) {
            if (isset($draft_config[$key]) && $draft_config[$key] !== "") {
                $config[$key] = (string)$draft_config[$key];
            }
        }
        if (isset($draft_config["show_count"])) {
            $config["show_count"] = filter_var($draft_config["show_count"], FILTER_VALIDATE_BOOLEAN);
        }

        // Unsaved View: id 0 so pagination params stay distinct from real Views and nothing is persisted.
        $view = new ViewConfig(0, $process_id, $match_key, $mode, $config);

        // "All pages" preview mirrors a hand-built hub page: the whole capped Page Set with no Match
        // value and no self-exclusion (current id null), through the same resolve + render path.
        if ($view->selection() === ViewConfig::SELECTION_ALL) {
            $post_ids = $this->viewResolver->resolve($view, array(), null, null);
            $limit = $this->resolveLimit($view, count($post_ids));
            $paginator = new ViewPaginator($post_ids, $limit, $page);
            $html = $this->viewRenderer->render($view, $paginator, "", "");
            return array(
                "html" => $this->renderOverCapDiagnostic($view) . $this->openLinksInNewTab($html),
                "total_pages" => $paginator->total_pages,
                "current_page" => $paginator->current_page,
            );
        }

        $current_page_data = $this->getCurrentPageData($sample_post_id);

        $match_value = $this->viewResolver->determine_match_value($view, $current_page_data, $explicit_value);
        if ($match_value === null) {
            return array(
                "html" => $this->renderMissingValueDiagnostic($view->match_key),
                "total_pages" => 1,
                "current_page" => 1,
            );
        }

        // SAME resolver + renderer as renderView — guarantees preview == front-end output.
        $post_ids = $this->viewResolver->resolve($view, $current_page_data, $sample_post_id, $explicit_value);
        $limit = $this->resolveLimit($view, count($post_ids));
        $paginator = new ViewPaginator($post_ids, $limit, $page);

        // Render WITHOUT a page param so the renderer emits no server <a href> pagination: in the
        // admin preview, paging is driven by React (re-requesting this endpoint per page) rather
        // than by URL navigation, which has no page to load here. Card/anchor links are rewritten
        // to open in a new tab so clicking one in the preview never navigates the admin away.
        $html = $this->viewRenderer->render($view, $paginator, "", "");

        return array(
            "html" => $this->openLinksInNewTab($html),
            "total_pages" => $paginator->total_pages,
            "current_page" => $paginator->current_page,
        );
    }

    /**
     * Rewrite anchor tags in preview HTML to open in a new tab, so clicking a card link in the
     * builder preview opens the target page without navigating away from the admin builder.
     */
    private function openLinksInNewTab(string $html): string
    {
        // Only inject target/rel on anchors that don't already carry a target (e.g. a View whose
        // link_target=new already rendered them), so we never emit a duplicate attribute pair.
        return (string)preg_replace('/<a\b(?![^>]*\btarget=)/i', '<a target="_blank" rel="noopener noreferrer"', $html);
    }

    /**
     * Every View across ALL Page Sets, for the global Views page. Each entry is a full,
     * JSON-serialisable View shape (id, process_id, name, match_key, mode, the copy-pasteable
     * shortcode token, and the decoded config — so Edit needs no second fetch), additionally
     * enriched with the owning Page Set's `purpose_with_name` label — computed exactly as the Manage table does
     * ({@see \LPagery\io\Mapper::lpagery_build_purpose_with_name}) so the two labels match. The
     * numeric `process_id` each entry already carries is what the builder/list group and scope by.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllViews(): array
    {
        $rows = $this->viewRepository->all();
        if (empty($rows)) {
            return array();
        }

        $views = array();
        foreach ($rows as $row) {
            $view = ViewConfig::from_row($row);
            $views[] = array(
                "id" => $view->id,
                "process_id" => $view->process_id,
                "name" => $view->name,
                "match_key" => $view->match_key,
                "mode" => $view->mode,
                "config" => $view->config,
                "shortcode" => '[lpagery_view id="' . $view->id . '"]',
                "purpose_with_name" => Mapper::lpagery_build_purpose_with_name(
                    $row->process_purpose,
                    (int)$row->process_post_id,
                    (string)$row->process_created
                ),
            );
        }
        return $views;
    }

    /**
     * Builder dialog metadata for a Process: the available placeholder keys (the union of the
     * column keys across the Process's page data) and a bounded list of sample Generated Pages
     * (id + title) for the live-preview context picker.
     *
     * Each entry in `key_stats` carries the placeholder key, the number of distinct trimmed
     * values it takes across the Process, the total number of pages that have a value, and a
     * small sample of distinct values. The frontend uses this to rank likely-good match keys
     * (a key repeated across many pages — low distinct_count relative to total — is a real
     * "dimension" like city; a key unique per page — distinct_count ≈ total — is a title or
     * image filename and a poor match key) and to show a "Boston, Seattle, +3 more" hint.
     *
     * @return array{
     *     placeholder_keys: string[],
     *     sample_pages: array<int, array{id:int,title:string}>,
     *     key_stats: array<int, array{key:string, distinct_count:int, total_count:int, sample_values:string[], is_image:bool}>
     * }
     */
    public function getBuilderMeta(int $process_id, int $sample_limit = 25): array
    {
        $rows = $this->generatedPageRepository->get_process_post_input_data($process_id);

        // Per key: a set of distinct (trimmed, lowercased) values, a few human-readable sample
        // values, and a count of pages that carried any value for the key.
        $sample_values_limit = 5;
        $distinct = array();      // key => [normalized_value => true]
        $samples = array();       // key => [display_value, ...] (bounded)
        $total = array();         // key => count of pages with a value
        $sample_pages = array();

        foreach ($rows as $row) {
            $data = maybe_unserialize($row->data);
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $key = (string)$key;
                    // Skip LPagery-internal config columns that aren't user-facing placeholders.
                    if ($key === "" || strpos($key, "lpagery_") === 0) {
                        continue;
                    }

                    if (!isset($distinct[$key])) {
                        $distinct[$key] = array();
                        $samples[$key] = array();
                        $total[$key] = 0;
                    }

                    $display = is_scalar($value) ? trim((string)$value) : "";
                    if ($display === "") {
                        continue;
                    }
                    $total[$key]++;

                    $normalized = strtolower($display);
                    if (!isset($distinct[$key][$normalized])) {
                        $distinct[$key][$normalized] = true;
                        if (count($samples[$key]) < $sample_values_limit) {
                            $samples[$key][] = $display;
                        }
                    }
                }
            }

            if (count($sample_pages) < $sample_limit && isset($row->post_id)) {
                $post_id = (int)$row->post_id;
                $title = function_exists("get_the_title") ? (string)get_the_title($post_id) : "";
                $sample_pages[] = array("id" => $post_id, "title" => $title !== "" ? $title : ("#" . $post_id));
            }
        }

        $placeholder_keys = array_keys($distinct);
        sort($placeholder_keys);

        $key_stats = array();
        foreach ($placeholder_keys as $key) {
            $key_stats[] = array(
                "key" => $key,
                "distinct_count" => count($distinct[$key]),
                "total_count" => $total[$key],
                "sample_values" => $samples[$key],
                // Image-ness is the plugin's single, name-based definition (a key whose name ends in
                // an image extension). The builder uses this to keep image columns out of the
                // match-key and text-slot pickers, and out of the image slot's (removed) placeholder
                // source. See Utils::lpagery_is_image_column.
                "is_image" => Utils::lpagery_is_image_column($key),
            );
        }

        return array(
            "placeholder_keys" => $placeholder_keys,
            "sample_pages" => $sample_pages,
            "key_stats" => $key_stats,
        );
    }

    /**
     * The distinct, trimmed values a single placeholder key takes across a Process, sorted
     * alphabetically and capped. Powers the preview's searchable match-value picker, so the user
     * chooses a real value (e.g. an actual city) instead of typing a free-text guess.
     *
     * @return string[]
     */
    public function getMatchKeyValues(int $process_id, string $key, int $limit = 1000): array
    {
        if ($key === "") {
            return array();
        }

        $rows = $this->generatedPageRepository->get_process_post_input_data($process_id);

        $seen = array();        // normalized value => true (dedupe, case/space-insensitive)
        $values = array();      // display values
        foreach ($rows as $row) {
            $data = maybe_unserialize($row->data);
            if (!is_array($data) || !array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (!is_scalar($value)) {
                continue;
            }
            $display = trim((string)$value);
            if ($display === "") {
                continue;
            }
            $normalized = strtolower($display);
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $values[] = $display;
            if (count($values) >= $limit) {
                break;
            }
        }

        sort($values, SORT_NATURAL | SORT_FLAG_CASE);
        return $values;
    }

    /**
     * The generated pages whose match-key value equals $value, as {id, title} for the preview's
     * optional "preview on a specific page" picker. Matching is case/space-insensitive, the same
     * normalization {@see getMatchKeyValues} uses, so it lines up with the values that picker offers.
     *
     * Picking one of these pages lets the preview self-exclude that exact page (showing the N-1
     * siblings a visitor sees on it); leaving it unset previews the full match set.
     *
     * @return array<int, array{id:int, title:string}>
     */
    public function getPagesForValue(int $process_id, string $key, string $value, int $limit = 1000): array
    {
        if ($key === "" || trim($value) === "") {
            return array();
        }

        $target = strtolower(trim($value));
        $rows = $this->generatedPageRepository->get_process_post_input_data($process_id);

        $pages = array();
        foreach ($rows as $row) {
            $data = maybe_unserialize($row->data);
            if (!is_array($data) || !array_key_exists($key, $data) || !is_scalar($data[$key])) {
                continue;
            }
            if (strtolower(trim((string)$data[$key])) !== $target) {
                continue;
            }
            if (!isset($row->post_id)) {
                continue;
            }

            $post_id = (int)$row->post_id;
            $title = function_exists("get_the_title") ? (string)get_the_title($post_id) : "";
            $pages[] = array("id" => $post_id, "title" => $title !== "" ? $title : ("#" . $post_id));
            if (count($pages) >= $limit) {
                break;
            }
        }

        return $pages;
    }

    private function loadView(int $view_id): ?ViewConfig
    {
        $row = $this->viewRepository->find($view_id);
        if ($row === null) {
            return null;
        }
        return ViewConfig::from_row($row);
    }

    /**
     * Read the current Generated Page's deserialized placeholder data. Returns an empty array
     * for non-generated pages (hand-built hubs), where the explicit value override is expected.
     */
    private function getCurrentPageData(?int $current_post_id): array
    {
        if ($current_post_id === null) {
            return array();
        }

        $process_post = $this->generatedPageRepository->get_process_post_data($current_post_id);
        if (empty($process_post) || empty($process_post->data)) {
            return array();
        }

        $data = maybe_unserialize($process_post->data);
        return is_array($data) ? $data : array();
    }
}
