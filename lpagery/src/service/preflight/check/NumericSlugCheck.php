<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;

/**
 * Numeric slugs, which WordPress renumbers ("1234" becomes "1234-2").
 */
class NumericSlugCheck implements PreflightCheck
{
    public function id(): string
    {
        return 'numeric-slugs';
    }

    public function run(PreflightContext $context): array
    {
        $row_ids = [];
        $examples = [];
        foreach ($context->rows() as $row) {
            if (is_numeric($row->slug)) {
                $row_ids[] = $row->row_id;
                $examples[] = ['slug' => $row->slug, 'row_ids' => [$row->row_id]];
            }
        }
        return empty($row_ids) ? [] : [Finding::rows(Finding::NOTE, 'numeric-slugs', $row_ids, $examples)];
    }
}
