<?php

namespace LPagery\service\preflight;

use JsonSerializable;

/**
 * One result of the Pre-flight Check (CONTEXT.md, *Finding*): a severity (Problem or Note), a
 * scope (Page Set or Row), a stable kind id and the structured parameters the frontend builds its
 * message from. A Row Finding also carries every affected `row_id`, the column to outline per row
 * and a few examples to show.
 */
class Finding implements JsonSerializable
{
    public const PROBLEM = 'problem';
    public const NOTE = 'note';

    public const SCOPE_PAGE_SET = 'page_set';
    public const SCOPE_ROW = 'row';

    // A Row Finding names every affected row but shows only this many examples.
    public const MAX_EXAMPLES = 5;

    private string $severity;
    private string $kind;
    private string $scope;
    /** @var array<string, mixed> */
    private array $params;
    /** @var int[] */
    private array $row_ids;
    /** @var array<int, string> row_id => column key */
    private array $columns;
    /** @var array<int, array{slug: string, permalink?: string, row_ids?: int[]}> */
    private array $examples;

    /**
     * @param array<string, mixed> $params
     * @param int[] $row_ids
     * @param array<int, string> $columns
     * @param array<int, array{slug: string, permalink?: string, row_ids?: int[]}> $examples
     */
    private function __construct(string $severity, string $kind, string $scope, array $params, array $row_ids, array $columns, array $examples)
    {
        $this->severity = $severity;
        $this->kind = $kind;
        $this->scope = $scope;
        $this->params = $params;
        $this->row_ids = $row_ids;
        $this->columns = $columns;
        $this->examples = array_slice($examples, 0, self::MAX_EXAMPLES);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function pageSet(string $severity, string $kind, array $params = []): self
    {
        return new self($severity, $kind, self::SCOPE_PAGE_SET, $params, [], [], []);
    }

    /**
     * @param int[] $row_ids
     * @param array<int, array{slug: string, permalink?: string, row_ids?: int[]}> $examples cut to {@see MAX_EXAMPLES}
     * @param array<string, mixed> $params
     * @param array<int, string> $columns row_id => the column key to outline in that row
     */
    public static function rows(string $severity, string $kind, array $row_ids, array $examples = [], array $params = [], array $columns = []): self
    {
        return new self($severity, $kind, self::SCOPE_ROW, $params, array_values($row_ids), $columns, $examples);
    }

    /**
     * A Row Finding about one column's values: every row is outlined in that column, and the
     * parameters name the column and up to {@see MAX_EXAMPLES} of the distinct values.
     *
     * @param array<int, string> $values_by_row row_id => the offending value
     * @param array<string, mixed> $params
     */
    public static function rowValues(string $severity, string $kind, string $column, array $values_by_row, array $params = []): self
    {
        $row_ids = array_keys($values_by_row);
        $values = array_slice(array_values(array_unique(array_map('strval', $values_by_row))), 0, self::MAX_EXAMPLES);
        return self::rows($severity, $kind, $row_ids, [], ['column' => $column, 'values' => $values] + $params,
            array_fill_keys($row_ids, $column));
    }

    public function jsonSerialize(): array
    {
        $json = [
            'kind' => $this->kind,
            'severity' => $this->severity,
            'scope' => $this->scope,
            // An object even when empty, so the frontend always reads a map.
            'params' => (object)$this->params,
        ];
        if ($this->scope === self::SCOPE_ROW) {
            $json['row_ids'] = $this->row_ids;
            $json['examples'] = $this->examples;
            if (!empty($this->columns)) {
                $json['columns'] = (object)$this->columns;
            }
        }
        return $json;
    }
}
