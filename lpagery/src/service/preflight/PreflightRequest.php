<?php

namespace LPagery\service\preflight;

use LPagery\utils\Utils;

/**
 * What the Pre-flight Check runs on: the Page Set's configuration plus the column keys and the
 * source rows, each carrying the browser's `row_id`. A row's `data` holds only the columns the
 * checks need.
 */
class PreflightRequest
{
    public int $template_id;
    public string $slug;
    public int $parent_id;
    public int $process_id;
    public bool $include_parent_as_identifier;
    /** @var string[] */
    public array $keys;
    /** @var array<int, array{row_id: int, data: array<string, mixed>}> */
    public array $rows;

    /**
     * @param string[] $keys
     * @param array<int, array{row_id: int, data: array<string, mixed>}> $rows
     */
    public function __construct(int $template_id, string $slug, int $parent_id, int $process_id, bool $include_parent_as_identifier, array $keys, array $rows)
    {
        $this->template_id = $template_id;
        $this->slug = $slug;
        $this->parent_id = $parent_id;
        $this->process_id = $process_id;
        $this->include_parent_as_identifier = $include_parent_as_identifier;
        $this->keys = $keys;
        $this->rows = $rows;
    }

    /**
     * Reads the request at the boundary, from the AJAX form (where `keys` and `rows` arrive as
     * slashed JSON strings) or from a REST body (plain arrays). Row values are sanitized later,
     * together with the rest of the row handling.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $keys = array_map(function ($key) {
            return sanitize_text_field((string)$key);
        }, array_values(array_filter(self::decode($raw['keys'] ?? []), 'is_scalar')));

        $rows = [];
        foreach (self::decode($raw['rows'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['row_id']) || !is_numeric($row['row_id'])) {
                continue;
            }
            $data = [];
            foreach (is_array($row['data'] ?? null) ? $row['data'] : [] as $column => $value) {
                if (is_scalar($value) || $value === null) {
                    $data[(string)$column] = (string)$value;
                }
            }
            $rows[] = ['row_id' => (int)$row['row_id'], 'data' => $data];
        }

        return new self(
            (int)($raw['post_id'] ?? 0),
            isset($raw['slug']) ? Utils::lpagery_sanitize_title_with_dashes((string)$raw['slug']) : '',
            (int)($raw['parent_id'] ?? 0),
            isset($raw['process_id']) ? (int)$raw['process_id'] : -1,
            rest_sanitize_boolean($raw['includeParentAsIdentifier'] ?? false),
            $keys,
            $rows
        );
    }

    /**
     * @param mixed $value a JSON string from the AJAX form or an already decoded array
     * @return array<int|string, mixed>
     */
    private static function decode($value): array
    {
        if (is_string($value)) {
            $value = json_decode(wp_unslash($value), true);
        }
        return is_array($value) ? $value : [];
    }

    public function withSlug(string $slug): self
    {
        $copy = clone $this;
        $copy->slug = $slug;
        return $copy;
    }
}
