<?php

namespace LPagery\model;

class Params extends BaseParams
{
    public bool $spintax_enabled = false;
    public bool $image_processing_enabled = false;
    public int $author_id;
    public array $source_attachment_ids = array();
    public array $target_attachment_ids = array();
    public int $process_id;
    public PageCreationDashboardSettings $settings;

    public array $image_keys = array();
    public array $image_values = array();



}