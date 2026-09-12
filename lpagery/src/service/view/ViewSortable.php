<?php

namespace LPagery\service\view;

/**
 * A matched candidate page carrying everything {@see ViewOrdering} needs to sort it, decoupled
 * from where the match came from. Both the index-backed and the live-deserialization resolver
 * paths build identical ViewSortable lists so ordering is the same either way (ADR-0001).
 */
class ViewSortable
{
    public int $post_id;
    /** WP post title — the alphabetical fallback when no placeholder label slot is configured. */
    public string $title;
    /** Page creation timestamp (the `created` column), used by the date-created presets. */
    public string $created;
    /** WordPress menu_order, used by the menu_order preset. */
    public int $menu_order;
    /** The page's deserialized placeholder data — source for label/custom-field ordering. */
    public array $data;
    /** Pre-resolved value of the custom ordering field (config['ordering_field']), if any. */
    public ?string $custom_field_value;

    public function __construct(
        int $post_id,
        string $title,
        string $created,
        int $menu_order,
        array $data,
        ?string $custom_field_value
    ) {
        $this->post_id = $post_id;
        $this->title = $title;
        $this->created = $created;
        $this->menu_order = $menu_order;
        $this->data = $data;
        $this->custom_field_value = $custom_field_value;
    }
}
