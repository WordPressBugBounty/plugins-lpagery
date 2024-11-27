<?php
namespace LPagery\model;

class PageCreationDashboardSettings
{
    public int $parent = 0;
    public array $categories = [];
    public array $tags = [];
    public string $slug;
    public string $status_from_process;
    public ?string $status_from_dashboard = null;
    public ?string $publish_datetime = null;

    public function __construct()
    {
    }

    public static function build_from_array(array $array) :PageCreationDashboardSettings
    {
        $pageCreationSettings = new self();
        $pageCreationSettings->parent = $array['parent_path'];
        $pageCreationSettings->categories = $array['categories'];
        $pageCreationSettings->tags = $array['tags'];
        $pageCreationSettings->slug = $array['slug'];
        $pageCreationSettings->status_from_process = $array['status'];
        $pageCreationSettings->publish_datetime = $array['publish_datetime'] ?? null;
        return $pageCreationSettings;
    }

}
