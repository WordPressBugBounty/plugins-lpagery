<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;
use LPagery\service\preflight\TemplateScan;

/**
 * Rows whose value is empty in a column the slug or the Template Page's title uses: the page gets a
 * slug or a title without that part. Columns match the Placeholders ignoring case and sanitization,
 * like the slug check, and Spintax braces are never taken for a Placeholder.
 */
class EmptyPlaceholderValueCheck implements PreflightCheck
{
    public function id(): string
    {
        return 'empty-values';
    }

    public function run(PreflightContext $context): array
    {
        $keys = $context->request()->keys;
        $findings = [];
        foreach ([
            'empty-slug-values' => $context->request()->slug,
            'empty-title-values' => $context->template_title(),
        ] as $kind => $text) {
            $finding = self::empty_rows($context, self::columns_used_by($text, $keys), $kind);
            if ($finding) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /**
     * The column keys whose Placeholder the text uses, in column order.
     *
     * @param string[] $keys
     * @return string[]
     */
    private static function columns_used_by(string $text, array $keys): array
    {
        $scan = new TemplateScan([$text]);
        return array_values(array_filter($keys, function ($key) use ($scan) {
            return $scan->uses_column($key);
        }));
    }

    /**
     * @param string[] $columns
     */
    private static function empty_rows(PreflightContext $context, array $columns, string $kind): ?Finding
    {
        if (empty($columns)) {
            return null;
        }
        $row_ids = [];
        $outlined = [];
        $empty_columns = [];
        foreach ($context->rows() as $row) {
            foreach ($columns as $column) {
                if (trim((string)($row->data[$column] ?? '')) === '') {
                    $row_ids[] = $row->row_id;
                    $outlined[$row->row_id] = $column;
                    $empty_columns[$column] = true;
                    break;
                }
            }
        }
        if (empty($row_ids)) {
            return null;
        }
        return Finding::rows(Finding::PROBLEM, $kind, $row_ids, [], ['columns' => array_map('strval', array_keys($empty_columns))], $outlined);
    }
}
