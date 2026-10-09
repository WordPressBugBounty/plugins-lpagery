<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;
use LPagery\utils\Utils;

/**
 * Below Extended, one Note naming the Image columns and the `lpagery_*` columns the run ignores,
 * in place of their value checks. The premium root replaces it with those checks on Extended.
 */
class ExtendedColumnsCheck implements PreflightCheck
{
    // The `lpagery_*` columns only Extended reads ({@see \LPagery\service\DynamicPageAttributeHandler}).
    public const EXTENDED_COLUMNS = ['lpagery_parent', 'lpagery_template', 'lpagery_author', 'lpagery_status', 'lpagery_publish_date', 'lpagery_content'];

    public function id(): string
    {
        return 'extended-columns';
    }

    public function run(PreflightContext $context): array
    {
        $columns = array_values(array_filter($context->request()->keys, function ($key) {
            return in_array($key, self::EXTENDED_COLUMNS, true) || Utils::lpagery_is_image_column($key);
        }));
        if (empty($columns)) {
            return [];
        }
        return [Finding::pageSet(Finding::NOTE, 'extended-columns-ignored', ['columns' => $columns])];
    }
}
