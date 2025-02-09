<?php
namespace LPagery\service\duplicates;

class ExistingSlugResult
{
    public string $slug;
    public int $parent_id;

    public function __construct(string $slug, int $parent_id)
    {
        $this->slug = $slug;
        $this->parent_id = $parent_id;
    }

}