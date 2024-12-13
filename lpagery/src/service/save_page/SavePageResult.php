<?php
namespace LPagery\service\save_page;
class SavePageResult
{
    public string $mode;
    public string $reason;
    public string $slug;

    public function __construct(string $mode, string $reason, string $slug)
    {
        $this->mode = $mode;
        $this->slug = $slug;
        $this->reason = $reason;
    }

}