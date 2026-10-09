<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;

/**
 * Rows that would create the same page: the same slug, and the same parent when the parent is part
 * of the page's identity. Only one of them is processed.
 */
class DuplicateRowsCheck implements PreflightCheck
{
    public function id(): string
    {
        return 'duplicate-rows';
    }

    public function run(PreflightContext $context): array
    {
        $include_parent = $context->request()->include_parent_as_identifier;
        $groups = [];
        foreach ($context->rows() as $row) {
            // WordPress gives a page without a slug a unique one, so empty slugs never collide.
            if ($row->slug === '') {
                continue;
            }
            $key = $row->slug . ($include_parent ? '|' . $row->parent_id : '');
            $groups[$key]['slug'] = $row->slug;
            $groups[$key]['row_ids'][] = $row->row_id;
        }

        $row_ids = [];
        $examples = [];
        foreach ($groups as $group) {
            if (count($group['row_ids']) < 2) {
                continue;
            }
            $row_ids = array_merge($row_ids, $group['row_ids']);
            $examples[] = $group;
        }
        if (empty($row_ids)) {
            return [];
        }
        sort($row_ids);
        return [Finding::rows(Finding::PROBLEM, 'duplicate-rows', $row_ids, $examples)];
    }
}
