<?php

namespace LPagery\model;

class Params extends BaseParams
{
    public bool $spintax_enabled = false;
    public bool $image_processing_enabled = false;
    public int $author_id = 0;
    public array $source_attachment_ids = array();
    public array $target_attachment_ids = array();
    public int $process_id = 0;
    public PageCreationDashboardSettings $settings;

    public array $image_keys = array();
    public array $image_values = array();
    public bool $force_update_content = false;
    public bool $overwrite_manual_changes = false;
    public bool $include_parent_as_identifier = false;
    public string $existing_page_update_action = "create";



}