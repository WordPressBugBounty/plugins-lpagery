<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;
use LPagery\service\preflight\TemplateScan;

/**
 * Placeholders the Template Page uses that no column fills, so the pages show them as written. One
 * within two edits of a column is most likely a typo, a Problem naming the column it probably
 * means. Every other one is listed in a single Note. Matching ignores case and sanitization, the
 * same way the slug check does.
 */
class TemplatePlaceholderCheck implements PreflightCheck
{
    private const MAX_TYPO_DISTANCE = 2;

    public function id(): string
    {
        return 'template-placeholders';
    }

    public function run(PreflightContext $context): array
    {
        $template = $context->template();
        $columns = [];
        foreach ($context->request()->keys as $key) {
            $columns[TemplateScan::normalize($key)] = $key;
        }

        $typos = [];
        $unknown = [];
        foreach ($template->placeholders() as $placeholder) {
            $normalized = TemplateScan::normalize($placeholder);
            if (isset($columns[$normalized]) || strpos($normalized, 'lpagery_') === 0) {
                continue;
            }
            $suggestion = $this->nearest_column($normalized, $columns);
            if ($suggestion !== null) {
                $typos[] = ['placeholder' => $placeholder, 'suggestion' => $suggestion];
            } else {
                $unknown[] = $placeholder;
            }
        }

        $findings = [];
        if (!empty($typos)) {
            $findings[] = Finding::pageSet(Finding::PROBLEM, 'placeholder-typos', ['typos' => $typos]);
        }
        if (!empty($unknown)) {
            $findings[] = Finding::pageSet(Finding::NOTE, 'unknown-placeholders', ['placeholders' => $unknown]);
        }
        return $findings;
    }

    /**
     * @param array<string, string> $columns normalized key => column key
     */
    private function nearest_column(string $placeholder, array $columns): ?string
    {
        $nearest = null;
        $nearest_distance = self::MAX_TYPO_DISTANCE + 1;
        foreach ($columns as $normalized => $column) {
            $distance = levenshtein($placeholder, (string)$normalized);
            if ($distance < $nearest_distance) {
                $nearest = $column;
                $nearest_distance = $distance;
            }
        }
        return $nearest;
    }
}
