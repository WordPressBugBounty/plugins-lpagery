<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;

/**
 * The rows `lpagery_ignore` skips, counted in one Note so the user sees how many pages the run
 * leaves out. Read from the rows as sent, since the resolved rows already leave them out.
 */
class IgnoredRowsCheck implements PreflightCheck
{
    public function id(): string
    {
        return 'ignored-rows';
    }

    public function run(PreflightContext $context): array
    {
        $row_ids = [];
        $columns = [];
        foreach ($context->request()->rows as $row) {
            if (array_key_exists('lpagery_ignore', $row['data']) && filter_var($row['data']['lpagery_ignore'], FILTER_VALIDATE_BOOLEAN)) {
                $row_ids[] = $row['row_id'];
                $columns[$row['row_id']] = 'lpagery_ignore';
            }
        }
        if (empty($row_ids)) {
            return [];
        }
        return [Finding::rows(Finding::NOTE, 'ignored-rows', $row_ids, [], ['count' => count($row_ids)], $columns)];
    }
}
