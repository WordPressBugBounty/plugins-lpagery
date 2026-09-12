<?php

namespace LPagery\service\view;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\model\view\ViewConfig;
use LPagery\service\image_endpoint\VirtualImageMap;
use LPagery\service\live_render\LiveListImageAttributes;

/**
 * Renders a resolved, ordered, self-excluded set of post ids into HTML for a View.
 *
 * Dispatches on the View's display mode ("list" or "grid") and fills each entry's Card slots
 * (label, image, sub-text, button) from their configured source — a placeholder column or a
 * WordPress post field (#164). Placeholder-sourced slots are filled from the matched pages'
 * deserialized row `data`, batch-loaded in one repository query to avoid an N+1 deserialize per card.
 *
 * The controller hands a {@see ViewPaginator} (the current page's id window + nav metadata)
 * plus a base URL and the per-View page query param; the renderer renders that page window and a
 * footer region below it. The footer holds an optional "Showing X of Y" count and server-rendered
 * numbered page links — the latter only when the View opts into pagination AND the matches span
 * more than one page (#165). "Show all" (pagination=none) renders just the first window with no
 * nav. No "load more"/AJAX in v1.
 *
 * Grid cards carry curated, per-View look-and-feel (columns/border/shadow/radius/spacing/align),
 * emitted as an id-scoped inline <style> override on top of the shared base CSS. Every knob is a
 * whitelisted enum mapped to a fixed CSS value — no raw user CSS is ever interpolated. Defaults
 * reproduce the original card look, so Views saved before these existed render unchanged.
 *
 * The render path is reachable from FREE code (ADR-0002): saved Views keep rendering after a
 * license downgrade. Matching/ordering is the resolver's job; this class only turns post ids
 * into markup and wraps the page nav.
 */
class ViewRenderer
{
    private GeneratedPageRepository $generatedPageRepository;
    private LiveListImageAttributes $listImageAttributes;

    public function __construct(GeneratedPageRepository $generatedPageRepository, LiveListImageAttributes $listImageAttributes)
    {
        $this->generatedPageRepository = $generatedPageRepository;
        $this->listImageAttributes = $listImageAttributes;
    }

    /**
     * Render the current page window of a paginated View.
     *
     * @param ViewConfig    $view       The View configuration.
     * @param ViewPaginator $paginator  The computed page window + nav metadata.
     * @param string        $base_url   The current page URL to preserve in nav links.
     * @param string        $page_param The per-View query param carrying the page number
     *                                   (e.g. "lpagery_view_page_3"), so multiple Views on one
     *                                   page paginate independently.
     */
    public function render(ViewConfig $view, ViewPaginator $paginator, string $base_url = "", string $page_param = ""): string
    {
        if (empty($paginator->page_ids)) {
            return "";
        }

        $post_ids = $paginator->page_ids;
        $slots = $view->get_slots();
        $rows = $this->generatedPageRepository->get_process_post_data_for_post_ids($post_ids);
        $placeholder_data = $this->index_row_data($rows);
        $virtual_pairs = $this->index_virtual_pairs($rows);
        $link_attrs = $this->link_target_attrs($view->link_target());
        $label_case = $view->label_case();

        if ($view->mode === ViewConfig::MODE_GRID) {
            $body = $this->render_grid($post_ids, $slots, $placeholder_data, $virtual_pairs, $link_attrs, $label_case);
        } else {
            $body = $this->render_list($post_ids, $slots, $placeholder_data, $link_attrs, $label_case);
        }

        $footer = $this->render_footer($view, $paginator, $base_url, $page_param);

        // Per-View appearance overrides are scoped to "#lpagery_view_{id}" so multiple Views on one
        // page can each carry their own border/shadow/columns without colliding. The base classes
        // still hold the shared structural CSS.
        $scope_id = "lpagery_view_" . $view->id;
        $wrapped = "<div class='lpagery_view' id='" . esc_attr($scope_id) . "'>" . $body . $footer . "</div>";

        // Ship self-contained styling with the markup so a View looks identical on the public
        // page and in the admin builder's live preview, with no separate stylesheet to enqueue
        // (and nothing to break on a license downgrade — the render path stays free, ADR-0002).
        return self::base_styles() . $this->appearance_styles($view, $scope_id) . $wrapped;
    }

    /**
     * The footer region rendered below the items (both modes): an optional "Showing X of Y" count
     * line, then numbered page nav. The count is independent of pagination — it can show even when
     * everything fits one page (pagination=none / single page).
     */
    private function render_footer(ViewConfig $view, ViewPaginator $paginator, string $base_url, string $page_param): string
    {
        $footer = "";

        if ($view->show_count()) {
            $footer .= $this->render_count($paginator);
        }

        $wants_pagination = $view->pagination() === ViewConfig::PAGINATION_NUMBERED;
        if ($wants_pagination && $paginator->has_pagination() && $page_param !== "") {
            $footer .= $this->render_pagination($paginator, $base_url, $page_param);
        }

        return $footer;
    }

    /**
     * "Showing X–Y of Z" line for the current page window.
     */
    private function render_count(ViewPaginator $paginator): string
    {
        $total = $paginator->total;
        $shown = count($paginator->page_ids);
        $first = ($paginator->current_page - 1) * $paginator->limit + 1;
        $last = $first + $shown - 1;

        if ($shown <= 0) {
            return "";
        }

        $text = $total > $shown
            ? sprintf("Showing %d\u{2013}%d of %d", $first, $last, $total)
            : sprintf("Showing %d of %d", $shown, $total);

        return "<div class='lpagery_view_count'>" . esc_html($text) . "</div>";
    }

    /**
     * The View's shared, View-independent CSS, emitted inline once per render. Scoped to
     * `.lpagery_view_*` classes so it can't leak into the surrounding theme/admin. Holds only the
     * structural defaults; per-View appearance overrides (border/shadow/columns/...) are layered on
     * by {@see appearance_styles()} with a higher-specificity, id-scoped rule. Browsers dedupe
     * identical inline <style> blocks cheaply, so repeating this per View on a page is fine.
     */
    private static function base_styles(): string
    {
        return "<style>"
            . ".lpagery_view_grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}"
            . ".lpagery_view_card{display:flex;flex-direction:column;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff;color:inherit;text-decoration:none;padding-bottom:14px;transition:box-shadow .15s,border-color .15s}"
            . ".lpagery_view_card:hover{border-color:#cbd5e1;box-shadow:0 4px 14px rgba(0,0,0,.07)}"
            . ".lpagery_view_card_image{aspect-ratio:16/10;background:#f3f4f6;margin-bottom:12px}"
            . ".lpagery_view_card_image img{width:100%;height:100%;object-fit:cover;display:block}"
            . ".lpagery_view_card_label{font-weight:600;font-size:15px;line-height:1.3;padding:0 14px;color:#111827}"
            // The first content element needs top spacing; when an image leads the card it already
            // supplies the gap, so only pad a label/sub-text that is itself the first child.
            . ".lpagery_view_card>.lpagery_view_card_label:first-child,.lpagery_view_card>.lpagery_view_card_sub_text:first-child{padding-top:14px}"
            . ".lpagery_view_card_sub_text{font-size:13px;color:#6b7280;padding:4px 14px 0}"
            . ".lpagery_view_list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px}"
            . ".lpagery_view_item{margin:0}"
            . ".lpagery_view_anchor{color:#2C5173;text-decoration:none}"
            . ".lpagery_view_anchor:hover{text-decoration:underline}"
            . ".lpagery_view_count{font-size:13px;color:#6b7280;margin-top:16px}"
            . ".lpagery_view_pagination{display:flex;gap:6px;margin-top:16px;flex-wrap:wrap}"
            . ".lpagery_view_page{display:inline-flex;min-width:30px;justify-content:center;padding:4px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;color:#374151;text-decoration:none}"
            . ".lpagery_view_page_current{background:#2C5173;border-color:#2C5173;color:#fff}"
            . "</style>";
    }

    /**
     * Per-View appearance overrides, scoped to "#{$scope_id}" so each View on a page styles only its
     * own cards. Every rule maps a curated enum (validated by {@see ViewConfig}) to a fixed CSS
     * value — we never interpolate user-supplied CSS, so the inline <style> is injection-safe.
     *
     * Appearance is grid-only: list mode keeps the clean text look, so we emit nothing for it.
     * Defaults are chosen so an unset config reproduces the original card exactly — an existing
     * View with no appearance keys gets only no-op or identical-to-base rules.
     */
    private function appearance_styles(ViewConfig $view, string $scope_id): string
    {
        if ($view->mode !== ViewConfig::MODE_GRID) {
            return "";
        }

        $sel = "#" . $scope_id;
        $rules = array();

        // Columns: cap the MAX column count while still reflowing down on narrow screens. "auto"
        // keeps the base auto-fill rule untouched. For a fixed N we keep auto-fill (so it can drop
        // below N when cramped) but cap the track count via a max grid width of N * (minwidth+gap),
        // which is the simplest reflow-safe way to bound columns without media queries.
        $columns = $view->columns();
        if ($columns !== ViewConfig::COLUMNS_AUTO) {
            $n = (int)$columns;
            // min track 220px + 16px gap; the container never grows past N tracks but shrinks freely.
            $max_width = $n * 220 + ($n - 1) * 16;
            $rules[] = "$sel .lpagery_view_grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));max-width:" . $max_width . "px}";
        }

        // Border weight.
        $border = $view->border();
        if ($border === ViewConfig::BORDER_NONE) {
            $rules[] = "$sel .lpagery_view_card{border-color:transparent}";
            $rules[] = "$sel .lpagery_view_card:hover{border-color:transparent}";
        } elseif ($border === ViewConfig::BORDER_STRONG) {
            $rules[] = "$sel .lpagery_view_card{border-width:2px;border-color:#9ca3af}";
            $rules[] = "$sel .lpagery_view_card:hover{border-color:#6b7280}";
        }
        // BORDER_SUBTLE == base (1px #e5e7eb), no override.

        // Shadow. "subtle" == base (hover-only lift), no override. "none" removes the hover lift;
        // "medium" adds a persistent resting shadow that deepens on hover.
        $shadow = $view->shadow();
        if ($shadow === ViewConfig::SHADOW_NONE) {
            $rules[] = "$sel .lpagery_view_card:hover{box-shadow:none}";
        } elseif ($shadow === ViewConfig::SHADOW_MEDIUM) {
            $rules[] = "$sel .lpagery_view_card{box-shadow:0 2px 8px rgba(0,0,0,.08)}";
            $rules[] = "$sel .lpagery_view_card:hover{box-shadow:0 6px 18px rgba(0,0,0,.12)}";
        }

        // Corner radius. "rounded" == base (10px), no override.
        $radius = $view->radius();
        if ($radius === ViewConfig::RADIUS_SQUARE) {
            $rules[] = "$sel .lpagery_view_card{border-radius:0}";
        } elseif ($radius === ViewConfig::RADIUS_PILL) {
            $rules[] = "$sel .lpagery_view_card{border-radius:20px}";
        }

        // Spacing drives the grid gap AND the card's inner padding together. "normal" == base
        // (gap 16 / pad 14), no override.
        $spacing = $view->spacing();
        if ($spacing === ViewConfig::SPACING_COMPACT) {
            $rules[] = "$sel .lpagery_view_grid{gap:10px}";
            $rules[] = "$sel .lpagery_view_card{padding-bottom:8px}";
            $rules[] = "$sel .lpagery_view_card_label{padding-left:10px;padding-right:10px}";
            $rules[] = "$sel .lpagery_view_card_sub_text{padding-left:10px;padding-right:10px}";
            $rules[] = "$sel .lpagery_view_card>.lpagery_view_card_label:first-child,$sel .lpagery_view_card>.lpagery_view_card_sub_text:first-child{padding-top:10px}";
        } elseif ($spacing === ViewConfig::SPACING_RELAXED) {
            $rules[] = "$sel .lpagery_view_grid{gap:24px}";
            $rules[] = "$sel .lpagery_view_card{padding-bottom:20px}";
            $rules[] = "$sel .lpagery_view_card_label{padding-left:20px;padding-right:20px}";
            $rules[] = "$sel .lpagery_view_card_sub_text{padding-left:20px;padding-right:20px}";
            $rules[] = "$sel .lpagery_view_card>.lpagery_view_card_label:first-child,$sel .lpagery_view_card>.lpagery_view_card_sub_text:first-child{padding-top:20px}";
        }

        // Text alignment. "left" == base, no override.
        if ($view->align() === ViewConfig::ALIGN_CENTER) {
            $rules[] = "$sel .lpagery_view_card{text-align:center}";
        }

        if (empty($rules)) {
            return "";
        }

        return "<style>" . implode("", $rules) . "</style>";
    }

    /**
     * List mode: the label slot drives the link text; the link always targets the related page's
     * own permalink (a card linking anywhere else makes no sense for a related-pages list).
     *
     * @param int[]                              $post_ids
     * @param array<string, array|null>          $slots
     * @param array<int, array<string, mixed>>   $placeholder_data
     * @param string                             $link_attrs Pre-rendered target/rel attributes.
     * @param string                             $label_case Label text-case transform.
     */
    private function render_list(array $post_ids, array $slots, array $placeholder_data, string $link_attrs, string $label_case): string
    {
        $list_items = "";
        foreach ($post_ids as $post_id) {
            $data = $placeholder_data[$post_id] ?? array();

            $label = $this->resolve_text_slot($slots[ViewConfig::SLOT_LABEL] ?? null, $post_id, $data);
            if ($label === "") {
                $label = (string)get_the_title($post_id);
            }
            $label = $this->apply_label_case($label, $label_case);

            $href = (string)get_permalink($post_id);

            $list_items .= "<li class='lpagery_view_item'><a class='lpagery_view_anchor' href='"
                . esc_url($href) . "'$link_attrs>" . esc_html($label) . "</a></li>";
        }

        return "<ul class='lpagery_view_list'>$list_items</ul>";
    }

    /**
     * Grid mode: a responsive card per matching page. Each card renders the configured slots;
     * omitted/unmapped slots are skipped. The whole card is a single anchor to the page permalink.
     *
     * @param int[]                              $post_ids
     * @param array<string, array|null>          $slots
     * @param array<int, array<string, mixed>>   $placeholder_data
     * @param array<int, array<string, mixed>>   $virtual_pairs   Per-entry Virtual Image Map (live siblings only).
     * @param string                             $link_attrs Pre-rendered target/rel attributes.
     * @param string                             $label_case Label text-case transform.
     */
    private function render_grid(array $post_ids, array $slots, array $placeholder_data, array $virtual_pairs, string $link_attrs, string $label_case): string
    {
        $cards = "";
        foreach ($post_ids as $post_id) {
            $data = $placeholder_data[$post_id] ?? array();
            $pairs = $virtual_pairs[$post_id] ?? array();
            $cards .= $this->render_card($post_id, $slots, $data, $pairs, $link_attrs, $label_case);
        }

        return "<div class='lpagery_view_grid'>$cards</div>";
    }

    /**
     * The whole card is a single `<a>` to the related page's own permalink — so a grid of cards
     * is a grid of links: keyboard-focusable, middle/cmd-clickable, no JavaScript. There is no
     * separate "View" button; the card itself is the affordance.
     *
     * @param array<string, array|null>  $slots
     * @param array<string, mixed>        $data
     * @param array<string, mixed>        $pairs The entry's persisted attachment pairs (Virtual Image Map for live siblings).
     */
    private function render_card(int $post_id, array $slots, array $data, array $pairs, string $link_attrs, string $label_case): string
    {
        $inner = "";

        $image_url = $this->resolve_image_slot($slots[ViewConfig::SLOT_IMAGE] ?? null, $post_id, $data);
        $label = $this->resolve_text_slot($slots[ViewConfig::SLOT_LABEL] ?? null, $post_id, $data);
        if ($label === "") {
            $label = (string)get_the_title($post_id);
        }
        $label = $this->apply_label_case($label, $label_case);

        if ($image_url !== "") {
            $image_alt = $this->resolve_image_alt($post_id, $data, $pairs, $label);
            $inner .= "<div class='lpagery_view_card_image'><img src='" . esc_url($image_url)
                . "' alt='" . esc_attr($image_alt) . "' loading='lazy' /></div>";
        }

        $inner .= "<div class='lpagery_view_card_label'>" . esc_html($label) . "</div>";

        $sub_text = $this->resolve_text_slot($slots[ViewConfig::SLOT_SUB_TEXT] ?? null, $post_id, $data);
        if ($sub_text !== "") {
            $inner .= "<div class='lpagery_view_card_sub_text'>" . esc_html($sub_text) . "</div>";
        }

        // A related-page card always links to that page's own permalink. With no permalink there
        // is nothing to link to, so fall back to a non-anchor container.
        $href = (string)get_permalink($post_id);
        if ($href === "") {
            return "<div class='lpagery_view_card'>$inner</div>";
        }

        return "<a class='lpagery_view_card' href='" . esc_url($href) . "'$link_attrs>$inner</a>";
    }

    /**
     * Pre-render the anchor target/rel attributes for a View's link-target setting. A new tab gets
     * `rel="noopener noreferrer"` automatically so callers never have to know the footgun.
     */
    private function link_target_attrs(string $link_target): string
    {
        if ($link_target === ViewConfig::LINK_TARGET_NEW) {
            return " target='_blank' rel='noopener noreferrer'";
        }
        return "";
    }

    /**
     * Apply the label slot's display-only text-case transform. Multibyte-safe so non-ASCII labels
     * (accented place/service names) capitalize correctly. Never touches the value used for
     * matching — this is purely how the already-resolved label string is rendered.
     */
    private function apply_label_case(string $label, string $label_case): string
    {
        if ($label === "" || $label_case === ViewConfig::LABEL_CASE_AS_IS) {
            return $label;
        }
        if ($label_case === ViewConfig::LABEL_CASE_TITLE) {
            return function_exists("mb_convert_case")
                ? mb_convert_case($label, MB_CASE_TITLE, "UTF-8")
                : ucwords(strtolower($label));
        }
        // Sentence case: first character upper, the rest as-is (so "iPhone repair" keeps "iPhone").
        $lower_first = function_exists("mb_substr")
            ? mb_strtoupper(mb_substr($label, 0, 1, "UTF-8"), "UTF-8") . mb_substr($label, 1, null, "UTF-8")
            : ucfirst($label);
        return $lower_first;
    }

    /**
     * Resolve a text-valued slot (label, sub-text, button-href) to a plain string. Returns "" when
     * the slot is unmapped or its source yields no value.
     *
     * @param array{type: string, key?: string, field?: string}|null $mapping
     * @param array<string, mixed>                                    $data
     */
    private function resolve_text_slot(?array $mapping, int $post_id, array $data): string
    {
        if ($mapping === null) {
            return "";
        }

        $type = $mapping["type"] ?? null;

        if ($type === ViewConfig::SOURCE_PLACEHOLDER) {
            $key = $mapping["key"] ?? "";
            if ($key !== "" && array_key_exists($key, $data) && $data[$key] !== null) {
                return (string)$data[$key];
            }
            return "";
        }

        if ($type === ViewConfig::SOURCE_WP_FIELD) {
            return $this->resolve_wp_field($mapping["field"] ?? "", $post_id);
        }

        return "";
    }

    /**
     * Resolve the image slot to a URL. The image slot is the page's featured image (WP field) or
     * nothing (ADR-0004); {@see ViewConfig::get_slots()} coerces any legacy image-placeholder
     * mapping back to the featured-image default before this runs, so the placeholder branch below
     * is defensive only.
     *
     * @param array{type: string, key?: string, field?: string}|null $mapping
     * @param array<string, mixed>                                    $data
     */
    private function resolve_image_slot(?array $mapping, int $post_id, array $data): string
    {
        if ($mapping === null) {
            return "";
        }

        $type = $mapping["type"] ?? null;

        if ($type === ViewConfig::SOURCE_PLACEHOLDER) {
            $key = $mapping["key"] ?? "";
            if ($key !== "" && array_key_exists($key, $data) && $data[$key] !== null) {
                return (string)$data[$key];
            }
            return "";
        }

        if ($type === ViewConfig::SOURCE_WP_FIELD) {
            return $this->resolve_wp_field($mapping["field"] ?? "featured_image", $post_id);
        }

        return "";
    }

    /**
     * Map a WordPress post field name to its value for a post.
     */
    private function resolve_wp_field(string $field, int $post_id): string
    {
        switch ($field) {
            case "title":
                return (string)get_the_title($post_id);
            case "excerpt":
                return (string)get_the_excerpt($post_id);
            case "permalink":
                return (string)get_permalink($post_id);
            case "featured_image":
                $url = get_the_post_thumbnail_url($post_id, "medium");
                return $url ? (string)$url : "";
            default:
                return "";
        }
    }

    /**
     * The card image's alt text. For a live sibling (ADR 0013) whose featured image is a virtual
     * source, substitute the source attachment's alt template with the entry's Row Data — the same
     * per-entry alt a theme archive gets from the loop-context filter. The View renderer supplies the
     * entry context explicitly because the global post here is the host page, not the sibling. Falls
     * back to the card label when the entry is not a live sibling or the source carries no alt.
     *
     * @param array<string, mixed> $data  the entry's Row Data
     * @param array<string, mixed> $pairs the entry's persisted attachment pairs
     */
    private function resolve_image_alt(int $post_id, array $data, array $pairs, string $label): string
    {
        if (empty($data) || !VirtualImageMap::is_virtual($pairs)) {
            return $label;
        }

        $source_id = (int)get_post_thumbnail_id($post_id);
        // Only substitute when the featured image really is one of the entry's virtual sources —
        // otherwise its alt template would never resolve and its raw braces would leak into the card.
        if ($source_id <= 0 || !$this->listImageAttributes->is_virtual_source($source_id, $pairs)) {
            return $label;
        }

        $source_alt = get_post_meta($source_id, '_wp_attachment_image_alt', true);
        if (!is_string($source_alt) || $source_alt === '') {
            return $label;
        }

        $substituted = $this->listImageAttributes->substitute(array('alt' => $source_alt), $source_id, $data, $pairs);
        $resolved = isset($substituted['alt']) ? (string)$substituted['alt'] : '';
        return $resolved !== '' ? $resolved : $label;
    }

    /**
     * Deserialize the placeholder `data` for each batch-loaded row, keyed by post id.
     *
     * @param array<int, object> $rows rows from {@see GeneratedPageRepository::get_process_post_data_for_post_ids()}
     * @return array<int, array<string, mixed>>
     */
    private function index_row_data(array $rows): array
    {
        $by_post_id = array();
        foreach ($rows as $row) {
            $data = maybe_unserialize($row->data);
            $by_post_id[(int)$row->post_id] = is_array($data) ? $data : array();
        }

        return $by_post_id;
    }

    /**
     * Deserialize each row's `attachment_id_pairs` into a per-post map, keeping only entries that carry
     * a Virtual Image Map (live copy-form siblings). Classic pages and legacy live sets — which have no
     * virtual map — are absent, so the card path pays for the source-attachment reads only when there is
     * an alt to substitute.
     *
     * @param array<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private function index_virtual_pairs(array $rows): array
    {
        $by_post_id = array();
        foreach ($rows as $row) {
            $raw = $row->attachment_id_pairs ?? null;
            if (empty($raw)) {
                continue;
            }
            $pairs = maybe_unserialize($raw);
            if (is_array($pairs) && VirtualImageMap::is_virtual($pairs)) {
                $by_post_id[(int)$row->post_id] = $pairs;
            }
        }

        return $by_post_id;
    }

    /**
     * Numbered, server-rendered page nav. Each link preserves the current URL and only changes
     * this View's page param, so other Views on the same page keep their own page.
     */
    private function render_pagination(ViewPaginator $paginator, string $base_url, string $page_param): string
    {
        $links = "";
        for ($page = 1; $page <= $paginator->total_pages; $page++) {
            $url = $this->build_page_url($base_url, $page_param, $page);
            if ($page === $paginator->current_page) {
                $links .= "<span class='lpagery_view_page lpagery_view_page_current' aria-current='page'>" . esc_html((string)$page) . "</span>";
            } else {
                $links .= "<a class='lpagery_view_page' href='" . esc_url($url) . "'>" . esc_html((string)$page) . "</a>";
            }
        }

        return "<nav class='lpagery_view_pagination'>$links</nav>";
    }

    /**
     * Build a page link by setting/overriding the page param on the current URL.
     */
    private function build_page_url(string $base_url, string $page_param, int $page): string
    {
        if (function_exists("add_query_arg")) {
            return (string)add_query_arg($page_param, $page, $base_url);
        }
        // Minimal fallback for non-WP contexts (tests cover the helper directly).
        $separator = strpos($base_url, "?") === false ? "?" : "&";
        return $base_url . $separator . rawurlencode($page_param) . "=" . $page;
    }
}
