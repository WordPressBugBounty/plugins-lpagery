<?php

namespace LPagery\model\view;

/**
 * A saved, named display configuration belonging to exactly one Process (a "View").
 *
 * Holds everything the resolver + renderer need: the source process, the match key
 * the View joins on, and the display mode. Richer config (card slot mappings, ordering,
 * limit/pagination) is layered on in later slices via the `config` array, which is kept
 * here so the storage shape is stable from slice 1.
 *
 * ## Card slot mapping shape (`config['slots']`)
 *
 * Each rendered entry (a Card in grid mode, a list row in list mode) has three assignable
 * slots: `label`, `image`, `sub_text`. The card's link is NOT a slot: the whole card is a
 * single anchor targeting the related page's own permalink (its open-in behaviour is controlled
 * by `config['link_target']`, not by a slot).
 *
 *     $config['slots'] = [
 *         'label'    => ['type' => 'placeholder', 'key'   => 'service'],
 *         'image'    => ['type' => 'wp_field',    'field' => 'featured_image'],
 *         'sub_text' => ['type' => 'placeholder', 'key'   => 'tagline'],
 *     ];
 *
 * - The `label` and `sub_text` slots map to a non-image Placeholder column (`type` =
 *   `placeholder`, `key` = the column) or a WordPress text field (`type` = `wp_field`, `field`
 *   one of `title`, `excerpt`, `permalink`). Mapping `label` to a placeholder is the
 *   "differentiator": cards read "Plumbing", not the full title "Plumbing in Boston".
 * - The `image` slot is the generated page's **featured image** (`featured_image` WP field) or
 *   nothing. It does NOT take a Placeholder column: a raw image cell value in `data[key]` is the
 *   original spreadsheet value (a Drive link, filename, or URL) that is not a guaranteed-usable
 *   `<img src>`, whereas the featured image is always resolved (ADR-0004). The builder offers only
 *   "Featured image" / "None" for this slot; a stored image-placeholder mapping from before this
 *   change is treated as the featured-image default.
 *
 * Slots may be omitted; {@see ViewConfig::get_slots()} fills sensible defaults: label = title
 * (WP field), image = featured_image (WP field), sub_text = none. When `config['slots']` is
 * entirely absent, list mode behaves exactly as in slice 1 (title link to the permalink).
 *
 * ## Selection config
 *
 * - `config['selection']`: `"match"` (default) or `"all"`. "match" renders the siblings sharing the
 *   Match value for `match_key`; "all" renders every Generated Page in the Page Set, ignoring the
 *   Match key and the meta index, capped at {@see ViewConfig::ALL_PAGES_CAP} (ADR-0005).
 *
 * ## Other display config
 *
 * - `config['link_target']`: `"same"` (default) or `"new"`. "new" renders the card/row anchor
 *   with `target="_blank" rel="noopener noreferrer"`.
 * - `config['label_case']`: `"as_is"` (default), `"sentence"`, or `"title"`. Restyles the label
 *   slot's text at render time only; never changes the stored value used for matching.
 *
 * ## Appearance config (grid mode only)
 *
 * Curated, discrete look-and-feel knobs for the card grid. Each is a small whitelist of named
 * options that maps to a fixed set of CSS values at render time (see {@see ViewRenderer}); we
 * never store raw CSS. Every key is optional and its default reproduces the original card look,
 * so Views saved before these existed render pixel-identical.
 *
 * - `config['columns']`: `"auto"` (default) or `"1".."6"` — the MAX column count on wide screens.
 *   The grid still reflows down on narrow viewports (never cramped). "auto" is the original
 *   auto-fill behaviour.
 * - `config['border']`: `"none" | "subtle" (default) | "strong"` — card border weight.
 * - `config['shadow']`: `"none" | "subtle" (default) | "medium"`. "subtle" keeps the original
 *   hover-only lift (no resting shadow); "medium" adds a persistent resting shadow; "none" is flat.
 * - `config['radius']`: `"square" | "rounded" (default) | "pill"` — card corner radius.
 * - `config['spacing']`: `"compact" | "normal" (default) | "relaxed"` — drives the grid gap AND the
 *   card's inner padding together ("offset").
 * - `config['align']`: `"left" (default) | "center"` — card text alignment.
 *
 * ## Footer config (both modes)
 *
 * - `config['pagination']`: `"numbered" (default)` keeps the server-rendered numbered page links;
 *   `"none"` renders all matched items up to `limit` with no page nav.
 * - `config['show_count']`: bool (default false) — render a "Showing X of Y" line below the items.
 */
class ViewConfig
{
    public const SLOT_LABEL = "label";
    public const SLOT_IMAGE = "image";
    public const SLOT_SUB_TEXT = "sub_text";
    public const SLOT_BUTTON = "button";

    public const SOURCE_PLACEHOLDER = "placeholder";
    public const SOURCE_WP_FIELD = "wp_field";

    public const MODE_LIST = "list";
    public const MODE_GRID = "grid";

    public const LINK_TARGET_SAME = "same";
    public const LINK_TARGET_NEW = "new";

    public const LABEL_CASE_AS_IS = "as_is";
    public const LABEL_CASE_SENTENCE = "sentence";
    public const LABEL_CASE_TITLE = "title";

    public const COLUMNS_AUTO = "auto";
    /** Allowed `columns` values: "auto" plus the string forms of 1..6 (max columns on wide screens). */
    public const COLUMNS_ALLOWED = array("auto", "1", "2", "3", "4", "5", "6");

    public const BORDER_NONE = "none";
    public const BORDER_SUBTLE = "subtle";
    public const BORDER_STRONG = "strong";
    public const BORDER_ALLOWED = array("none", "subtle", "strong");

    public const SHADOW_NONE = "none";
    public const SHADOW_SUBTLE = "subtle";
    public const SHADOW_MEDIUM = "medium";
    public const SHADOW_ALLOWED = array("none", "subtle", "medium");

    public const RADIUS_SQUARE = "square";
    public const RADIUS_ROUNDED = "rounded";
    public const RADIUS_PILL = "pill";
    public const RADIUS_ALLOWED = array("square", "rounded", "pill");

    public const SPACING_COMPACT = "compact";
    public const SPACING_NORMAL = "normal";
    public const SPACING_RELAXED = "relaxed";
    public const SPACING_ALLOWED = array("compact", "normal", "relaxed");

    public const ALIGN_LEFT = "left";
    public const ALIGN_CENTER = "center";
    public const ALIGN_ALLOWED = array("left", "center");

    public const PAGINATION_NUMBERED = "numbered";
    public const PAGINATION_NONE = "none";
    public const PAGINATION_ALLOWED = array("numbered", "none");

    public const SELECTION_MATCH = "match";
    public const SELECTION_ALL = "all";
    public const SELECTION_ALLOWED = array("match", "all");

    /**
     * Hard upper bound on how many Generated Pages an "All pages" View ever loads/renders. All-pages
     * has no Match value to reduce the set and bypasses the meta index entirely, so it is capped
     * rather than scaled (ADR-0005). Enforced at the DAO `LIMIT` (ordered `created ASC`); a Page Set
     * larger than this shows the first {@see ALL_PAGES_CAP} pages plus an admin-only diagnostic.
     */
    public const ALL_PAGES_CAP = 1000;

    public int $id;
    public int $process_id;
    /** Human-given name shown in the builder list (e.g. "Services in this city"). */
    public string $name;
    /** The single placeholder key this View joins on (e.g. "location"). Empty in "all" selection mode. */
    public string $match_key;
    /** Display mode: "list" or "grid". Defaults to "list" in slice 1. */
    public string $mode;
    /** Free-form extra configuration (slot mappings, ordering, limit) for later slices. */
    public array $config;

    public function __construct(int $id, int $process_id, string $match_key, string $mode = "list", array $config = array(), string $name = "")
    {
        $this->id = $id;
        $this->process_id = $process_id;
        $this->name = $name;
        $this->match_key = $match_key;
        $this->mode = $mode;
        $this->config = $config;
    }

    /**
     * The effective slot → source mapping, merging any configured `config['slots']` over the
     * sensible defaults (label=title, image=featured_image, sub_text=none). A `null` entry means
     * "render nothing for this slot". The card's link is not a configurable slot — it always
     * targets the related page's permalink (see {@see ViewRenderer}).
     *
     * @return array<string, array{type: string, key?: string, field?: string}|null>
     */
    public function get_slots(): array
    {
        $defaults = array(
            self::SLOT_LABEL => array("type" => self::SOURCE_WP_FIELD, "field" => "title"),
            self::SLOT_IMAGE => array("type" => self::SOURCE_WP_FIELD, "field" => "featured_image"),
            self::SLOT_SUB_TEXT => null,
        );

        $configured = isset($this->config["slots"]) && is_array($this->config["slots"])
            ? $this->config["slots"]
            : array();

        $slots = $defaults;
        foreach (array(self::SLOT_LABEL, self::SLOT_IMAGE, self::SLOT_SUB_TEXT) as $slot) {
            if (array_key_exists($slot, $configured)) {
                $mapping = $configured[$slot];
                $slots[$slot] = is_array($mapping) && isset($mapping["type"]) ? $mapping : null;
            }
        }

        // The image slot no longer accepts a Placeholder column (ADR-0004); a placeholder mapping
        // can only come from a View saved before this change. Coerce it back to the featured-image
        // default so old configs render the always-resolvable image instead of a raw cell value.
        if (isset($slots[self::SLOT_IMAGE]["type"]) && $slots[self::SLOT_IMAGE]["type"] === self::SOURCE_PLACEHOLDER) {
            $slots[self::SLOT_IMAGE] = $defaults[self::SLOT_IMAGE];
        }

        return $slots;
    }

    /**
     * How the card/row anchor opens: same tab (default) or a new tab. A new tab renders the
     * anchor with `target="_blank" rel="noopener noreferrer"` (see {@see ViewRenderer}).
     */
    public function link_target(): string
    {
        $value = isset($this->config["link_target"]) ? (string)$this->config["link_target"] : self::LINK_TARGET_SAME;
        return $value === self::LINK_TARGET_NEW ? self::LINK_TARGET_NEW : self::LINK_TARGET_SAME;
    }

    /**
     * The label slot's render-time text-case transform: as-is (default), sentence, or title.
     * Display only — never changes the stored value used for matching.
     */
    public function label_case(): string
    {
        $value = isset($this->config["label_case"]) ? (string)$this->config["label_case"] : self::LABEL_CASE_AS_IS;
        return in_array($value, array(self::LABEL_CASE_SENTENCE, self::LABEL_CASE_TITLE), true)
            ? $value
            : self::LABEL_CASE_AS_IS;
    }

    /**
     * Read one of the appearance/footer enum keys, falling back to its default when the stored
     * value is missing or not in the whitelist. Defensive on read as well as on write: a config
     * tampered past the sanitizer (or hand-edited in the DB) can never inject an arbitrary value
     * into the rendered inline CSS.
     *
     * @param string[] $allowed
     */
    private function enum_value(string $key, array $allowed, string $default): string
    {
        $value = isset($this->config[$key]) ? (string)$this->config[$key] : $default;
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** Max grid columns on wide screens: "auto" (auto-fill) or "1".."6". Grid mode only. */
    public function columns(): string
    {
        return $this->enum_value("columns", self::COLUMNS_ALLOWED, self::COLUMNS_AUTO);
    }

    /** Card border weight: none / subtle (default, original 1px) / strong. */
    public function border(): string
    {
        return $this->enum_value("border", self::BORDER_ALLOWED, self::BORDER_SUBTLE);
    }

    /** Card shadow: none / subtle (default, hover-only lift) / medium (resting shadow). */
    public function shadow(): string
    {
        return $this->enum_value("shadow", self::SHADOW_ALLOWED, self::SHADOW_SUBTLE);
    }

    /** Card corner radius: square / rounded (default, original 10px) / pill. */
    public function radius(): string
    {
        return $this->enum_value("radius", self::RADIUS_ALLOWED, self::RADIUS_ROUNDED);
    }

    /** Grid gap + card inner padding ("offset"): compact / normal (default) / relaxed. */
    public function spacing(): string
    {
        return $this->enum_value("spacing", self::SPACING_ALLOWED, self::SPACING_NORMAL);
    }

    /** Card text alignment: left (default) / center. */
    public function align(): string
    {
        return $this->enum_value("align", self::ALIGN_ALLOWED, self::ALIGN_LEFT);
    }

    /** Footer page nav: numbered (default, server-rendered links) / none (show all up to limit). */
    public function pagination(): string
    {
        return $this->enum_value("pagination", self::PAGINATION_ALLOWED, self::PAGINATION_NUMBERED);
    }

    /** Whether to render a "Showing X of Y" count line below the items. Default false. */
    public function show_count(): bool
    {
        return !empty($this->config["show_count"]);
    }

    /**
     * Which of the Page Set's Generated Pages this View renders. "match" (default) shows only the
     * siblings sharing the Match value for {@see $match_key}; "all" shows every Generated Page in
     * the Page Set (capped at {@see ALL_PAGES_CAP}, ignoring the Match key). See ADR-0005.
     */
    public function selection(): string
    {
        return $this->enum_value("selection", self::SELECTION_ALLOWED, self::SELECTION_MATCH);
    }

    /**
     * Build a ViewConfig from a raw DB row (stdClass from $wpdb->get_results).
     */
    public static function from_row(object $row): ViewConfig
    {
        $config = array();
        if (!empty($row->config)) {
            $decoded = json_decode($row->config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        $mode = isset($row->mode) && $row->mode ? (string)$row->mode : "list";
        $name = isset($row->name) ? (string)$row->name : "";

        return new ViewConfig((int)$row->id, (int)$row->process_id, (string)$row->match_key, $mode, $config, $name);
    }
}
