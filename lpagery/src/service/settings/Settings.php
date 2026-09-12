<?php
namespace LPagery\service\settings;
class Settings
{
    public bool $spintax;
    public bool $image_processing;
    public bool $image_partial_match;
    public array $custom_post_types;
    public int $author_id;
    public string $hierarchical_taxonomy_handling;
    public bool $google_sheet_sync_enabled;
    public bool $google_sheet_sync_force_update;
    public bool $google_sheet_sync_overwrite_manual_changes;
    public int $sync_batch_size;
    public string $google_sheet_sync_interval;
    public ?string $next_google_sheet_sync;
    public ?bool $wp_cron_disabled;
    public bool $hide_generated_pages;
    public string $default_render_mode = 'classic';
    public bool $default_background_generation = false;
    public bool $virtual_images_enabled = false;
    // Read-only provenance for the Virtual Image URLs default (issue #233): how the Edge Cache Probe
    // judged this site — 'detected_on' (an Edge Cache absorbed the probe), 'detected_off' (none did), or
    // 'undetermined' (no conclusive verdict yet). Never accepted on the save path; GET response only.
    public string $virtual_images_detection = 'undetermined';
}