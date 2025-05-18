<?php
namespace LPagery\model;

class PageCreationDashboardSettings
{
    public int $parent = 0;
    public array $taxonomy_terms = [];
    public array $categories = [];
    public array $tags = [];
    public string $slug;
    public ?string $client_generated_slug = null;
    public string $status_from_process;
    public ?string $status_from_dashboard = null;
    public ?string $publish_datetime = null;

    public function __construct()
    {
    }

}
