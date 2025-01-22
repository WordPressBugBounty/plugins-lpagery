<?php
namespace LPagery\service\settings;
class Settings
{
    public bool $spintax;
    public bool $image_processing;
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
}