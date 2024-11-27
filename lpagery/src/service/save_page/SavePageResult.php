<?php
namespace LPagery\service\save_page;
class SavePageResult
{
    public string $mode;
    public string $slug;

    public function __construct(string $mode, string $slug)
    {
        $this->mode = $mode;
        $this->slug = $slug;
    }

}