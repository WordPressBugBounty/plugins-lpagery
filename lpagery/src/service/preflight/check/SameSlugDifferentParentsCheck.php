<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;

/**
 * The same slug under different parents, while the slug alone identifies a page. Turning on
 * "parent as identifier" creates one page per parent instead.
 */
class SameSlugDifferentParentsCheck implements PreflightCheck
{
    public function id(): string
    {
        return 'same-slug-different-parents';
    }

    public function run(PreflightContext $context): array
    {
        if ($context->request()->include_parent_as_identifier) {
            return [];
        }
        $parents_by_slug = [];
        foreach ($context->rows() as $row) {
            // WordPress gives a page without a slug a unique one, so empty slugs never collide.
            if ($row->slug === '') {
                continue;
            }
            $parents_by_slug[$row->slug][$row->parent_id] = true;
            if (count($parents_by_slug[$row->slug]) > 1) {
                return [Finding::pageSet(Finding::PROBLEM, 'same-slug-different-parents')];
            }
        }
        return [];
    }
}
