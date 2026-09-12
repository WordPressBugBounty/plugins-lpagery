<?php

namespace LPagery\service\view;

use LPagery\model\view\ViewConfig;

/**
 * Orders a View's matched candidate pages into a stable, fully-ordered list of post ids,
 * applied BEFORE any limit/pagination window is taken (slice #165).
 *
 * Ordering is computed here — not in SQL — so it is byte-identical regardless of whether the
 * matched ids came from the sparse meta index path or the live-deserialization path
 * (ADR-0001 behavior invariance). Both resolver paths build the same {@see ViewSortable}
 * candidates (post id + the sort keys: WP title, created timestamp, WP menu_order, and the
 * value of the configured custom placeholder field) and hand them here.
 *
 * Supported presets (read from `config['ordering']`):
 *  - "alphabetical"      (default) — by the label slot's value when a placeholder label slot is
 *                         configured in `config['slots']['label']` (type "placeholder"); otherwise
 *                         falls back to the WordPress post title. The label-slot source is owned by
 *                         slice #164; this slice reads it defensively and falls back when absent.
 *  - "date_created_desc" — newest first, by the page's `created` timestamp.
 *  - "date_created_asc"  — oldest first.
 *  - "custom_field"      — by the value of the placeholder key named in `config['ordering_field']`.
 *  - "menu_order"        — by WordPress `menu_order` (the manual order users can set per page).
 *
 * Ties (and any unknown/missing preset) break by ascending post id, so the result is always
 * deterministic and the index/live paths cannot diverge on equal sort keys.
 */
class ViewOrdering
{
    const ALPHABETICAL = "alphabetical";
    const DATE_CREATED_DESC = "date_created_desc";
    const DATE_CREATED_ASC = "date_created_asc";
    const CUSTOM_FIELD = "custom_field";
    const MENU_ORDER = "menu_order";

    /**
     * Order the candidates and return the resulting post ids.
     *
     * @param ViewConfig     $view       The View whose `config` carries the ordering preset.
     * @param ViewSortable[] $candidates Matched, not-yet-ordered candidates (already self-excluded).
     *
     * @return int[] post ids in display order
     */
    public static function order(ViewConfig $view, array $candidates): array
    {
        $preset = self::resolve_preset($view);

        usort($candidates, static function (ViewSortable $a, ViewSortable $b) use ($preset, $view) {
            $cmp = self::compare($preset, $view, $a, $b);
            if ($cmp !== 0) {
                return $cmp;
            }
            // Deterministic tie-break: ascending post id. Guarantees index == live output.
            return $a->post_id <=> $b->post_id;
        });

        return array_map(static function (ViewSortable $candidate) {
            return $candidate->post_id;
        }, $candidates);
    }

    /**
     * The ordering preset to apply, read defensively from the View config with the
     * alphabetical-by-label default.
     */
    private static function resolve_preset(ViewConfig $view): string
    {
        $preset = isset($view->config["ordering"]) ? (string)$view->config["ordering"] : self::ALPHABETICAL;
        $known = array(
            self::ALPHABETICAL,
            self::DATE_CREATED_DESC,
            self::DATE_CREATED_ASC,
            self::CUSTOM_FIELD,
            self::MENU_ORDER,
        );
        return in_array($preset, $known, true) ? $preset : self::ALPHABETICAL;
    }

    private static function compare(string $preset, ViewConfig $view, ViewSortable $a, ViewSortable $b): int
    {
        switch ($preset) {
            case self::DATE_CREATED_DESC:
                return strcmp((string)$b->created, (string)$a->created);
            case self::DATE_CREATED_ASC:
                return strcmp((string)$a->created, (string)$b->created);
            case self::MENU_ORDER:
                return $a->menu_order <=> $b->menu_order;
            case self::CUSTOM_FIELD:
                return self::compare_strings($a->custom_field_value, $b->custom_field_value);
            case self::ALPHABETICAL:
            default:
                return self::compare_strings(self::alphabetical_key($view, $a), self::alphabetical_key($view, $b));
        }
    }

    /**
     * The string to sort on for the alphabetical preset: the label slot's placeholder value
     * when a placeholder label slot is configured, else the WP post title fallback.
     */
    private static function alphabetical_key(ViewConfig $view, ViewSortable $candidate): string
    {
        $label_key = self::label_placeholder_key($view);
        if ($label_key !== null && isset($candidate->data[$label_key])) {
            $value = $candidate->data[$label_key];
            if ($value !== null && $value !== "") {
                return (string)$value;
            }
        }
        return (string)$candidate->title;
    }

    /**
     * The placeholder key feeding the label slot, if one is configured by slice #164's slot
     * mapping (`config['slots']['label'] = ['type' => 'placeholder', 'key' => '...']`). Read
     * defensively: returns null when slots are absent (e.g. before #164 merges) or the label
     * slot is not a placeholder, so we fall back to the post title.
     */
    private static function label_placeholder_key(ViewConfig $view): ?string
    {
        $slots = $view->config["slots"] ?? null;
        if (!is_array($slots) || !isset($slots["label"]) || !is_array($slots["label"])) {
            return null;
        }
        $label = $slots["label"];
        $type = $label["type"] ?? null;
        $key = $label["key"] ?? null;
        if ($type === "placeholder" && is_string($key) && $key !== "") {
            return $key;
        }
        return null;
    }

    /**
     * Case-insensitive, natural string comparison so "Item 2" sorts before "Item 10" and minor
     * case differences don't reorder the list surprisingly.
     */
    private static function compare_strings(?string $a, ?string $b): int
    {
        return strnatcasecmp((string)$a, (string)$b);
    }
}
