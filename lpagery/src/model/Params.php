<?php

namespace LPagery\model;

class Params extends BaseParams
{
    public bool $spintax_enabled = false;
    /** @var int|null The Generated Page's Spin Seed; null means an unseeded (random) spintax pick. */
    public ?int $spin_seed = null;
    public bool $image_processing_enabled = false;
    public int $author_id = 0;
    public array $source_attachment_ids = array();
    public array $target_attachment_ids = array();
    /** @var array<string,array{source_id:int,filename:string}> Live Mode Virtual Image Map (ADR 0013), keyed by Image column. */
    public array $virtual_image_map = array();
    public int $process_id = 0;
    public PageCreationDashboardSettings $settings;

    public array $image_keys = array();
    public array $image_values = array();
    public bool $force_update_content = false;
    public bool $overwrite_manual_changes = false;
    public bool $include_parent_as_identifier = false;
    public string $existing_page_update_action = "create";
    public string $render_mode = 'classic';



}