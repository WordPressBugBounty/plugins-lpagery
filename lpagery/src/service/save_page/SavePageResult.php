<?php
namespace LPagery\service\save_page;
use LPagery\model\Params;

class SavePageResult
{
    public string $mode;
    public string $reason;
    public string $slug;
    public ?CreatedPageCacheValue $createdPageCacheValue;

    public function __construct(string $mode, string $reason, string $slug, ?CreatedPageCacheValue $createdPageCacheValue)
    {
        $this->mode = $mode;
        $this->slug = $slug;
        $this->reason = $reason;
        $this->createdPageCacheValue = $createdPageCacheValue;
    }

    public static function create(string $mode, string $reason, string $slug, Params $params, ?int $parent_id) {
        return new SavePageResult($mode, $reason, $slug, CreatedPageCacheValue::create($params, $slug, $parent_id));
    }

}