<?php
namespace LPagery\service\save_page;
use LPagery\model\Params;

class CreatedPageCacheValue
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;

    }

    public static function create(Params $params, string $slug, ?int $parent_id)
    {
        $transient_key = $slug;
        if ($params->include_parent_as_identifier && $parent_id) {
            $transient_key .= "|||$parent_id";
        }
        return new CreatedPageCacheValue($transient_key);
    }
}