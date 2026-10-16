<?php

namespace LPagery\service\lookup;

use LPagery\multilingual\MultilingualPlugin;

/**
 * The term side of the App Connection lookups: the term picker and the batch resolve of term values.
 */
class TermLookup
{
    private ?MultilingualPlugin $multilingualPlugin;

    public function __construct(?MultilingualPlugin $multilingualPlugin)
    {
        $this->multilingualPlugin = $multilingualPlugin;
    }

    /**
     * The terms of one taxonomy whose name matches the search, by name.
     *
     * With a Page Language, a term is left out when the Multilingual Plugin names another term of
     * the same name as that language's: the sync would never take it by name. The plugin offers
     * no way to filter a term list by language, so a term whose name exists only in another
     * language stays listed, and a page can hold fewer items than `per_page`.
     *
     * @param \WP_Taxonomy|object $taxonomy
     * @param string $language the Page Language, or '' for none
     * @return array{items: array<int, array{id: int, name: string, path: string}>, has_more: bool}
     */
    public function search($taxonomy, string $search, int $page, int $per_page, string $language): array
    {
        global $wpdb;
        $args = [$taxonomy->name];
        $search_condition = '';
        if ($search !== '') {
            $search_condition = 'AND t.name LIKE %s';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        // One row past the page tells whether another page follows.
        array_push($args, $per_page + 1, ($page - 1) * $per_page);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, tt.parent FROM $wpdb->terms t
             INNER JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = %s $search_condition
             ORDER BY t.name ASC, t.term_id ASC
             LIMIT %d OFFSET %d",
            $args
        )) ?: [];

        $page_rows = array_slice($rows, 0, $per_page);
        if ($this->multilingualPlugin !== null && $language !== '') {
            $page_rows = $this->without_other_language_twins($taxonomy->name, $page_rows, $language);
        }
        $paths = $this->paths($taxonomy, $page_rows);
        $items = array_map(function ($row) use ($paths) {
            return ['id' => (int)$row->term_id, 'name' => (string)$row->name, 'path' => $paths[(int)$row->term_id]];
        }, $page_rows);
        return ['items' => $items, 'has_more' => count($rows) > $per_page];
    }

    /**
     * @param object[] $rows
     * @return object[] the rows the Multilingual Plugin does not answer with another term of the same name
     */
    private function without_other_language_twins(string $taxonomy, array $rows, string $language): array
    {
        $language_terms = [];
        foreach ($rows as $row) {
            $name = (string)$row->name;
            if (!array_key_exists($name, $language_terms)) {
                $language_terms[$name] = $this->multilingualPlugin->find_term($name, $taxonomy, $language);
            }
        }
        return array_values(array_filter($rows, function ($row) use ($language_terms) {
            $language_term = $language_terms[(string)$row->name];
            return $language_term === null || $language_term === (int)$row->term_id;
        }));
    }

    /**
     * The term each value names, keyed by the value, matched the way the sync's taxonomy save
     * matches a sheet value ({@see \LPagery\service\taxonomies\TaxonomyAssigner}): the id of a
     * term of this taxonomy, else the last segment of a `>` path by name, asking the Multilingual
     * Plugin for the term of the Page Language first. A value that names no term is left out,
     * because the sync will create it.
     *
     * @param \WP_Taxonomy|object $taxonomy
     * @param string[] $values distinct values
     * @param string $language the Page Language, or '' for none
     * @return array<string, array{found: true, id: int, label: string}>
     */
    public function resolve($taxonomy, array $values, string $language): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($values, 'is_numeric')), function ($id) {
            return $id > 0;
        })));
        $by_id = $this->load_terms($taxonomy->name, $ids);

        $term_ids = [];
        $names = [];
        foreach ($values as $value) {
            if (is_numeric($value) && isset($by_id[(int)$value])) {
                $term_ids[$value] = (int)$value;
                continue;
            }
            $name = $this->name_to_look_up($taxonomy, $value);
            if ($name !== '') {
                $names[$value] = $name;
            }
        }
        $unique_names = array_values(array_unique($names));
        $by_name = [];
        if ($this->multilingualPlugin !== null && $language !== '') {
            foreach ($unique_names as $name) {
                $term_id = $this->multilingualPlugin->find_term($name, $taxonomy->name, $language);
                if ($term_id !== null) {
                    $by_name[$name] = $term_id;
                }
            }
        }
        $by_name += $this->find_terms_by_name($taxonomy->name, array_values(array_diff($unique_names, array_keys($by_name))));
        foreach ($names as $value => $name) {
            if (isset($by_name[$name])) {
                $term_ids[$value] = $by_name[$name];
            }
        }

        $terms = $by_id + $this->load_terms($taxonomy->name, array_values(array_diff(array_unique($term_ids), array_keys($by_id))));
        $paths = $this->paths($taxonomy, array_values($terms));
        $resolved = [];
        foreach ($term_ids as $value => $term_id) {
            if (!isset($paths[$term_id])) {
                continue;
            }
            $resolved[(string)$value] = ['found' => true, 'id' => $term_id, 'label' => $paths[$term_id]];
        }
        return $resolved;
    }

    /**
     * The name the sync looks a value up by: the last segment of a `>` path. A hierarchical
     * taxonomy walks the path and skips empty segments, so its last non-empty one counts.
     *
     * @param \WP_Taxonomy|object $taxonomy
     */
    private function name_to_look_up($taxonomy, string $value): string
    {
        $segments = explode('>', $value);
        if ($taxonomy->hierarchical) {
            $segments = array_values(array_filter($segments, function ($segment) {
                return trim($segment) !== '';
            }));
        }
        return trim((string)end($segments));
    }

    /**
     * The term of each name, the lowest id where several share one. Names compare the way the
     * database compares them, as WordPress's own lookup by name does, and also in the encoded
     * form WordPress stores (`&` as `&amp;`).
     *
     * @param string[] $names distinct names
     * @return array<string, int> term id keyed by name
     */
    private function find_terms_by_name(string $taxonomy, array $names): array
    {
        global $wpdb;
        $names = array_values($names);
        if (empty($names)) {
            return [];
        }
        // Each name is carried as its index: grouping by the name itself would fold names that
        // differ only in case into one row and lose the others.
        $candidates = [];
        $args = [];
        foreach ($names as $index => $name) {
            foreach (array_unique([$name, htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8', false)]) as $candidate) {
                $candidates[] = 'SELECT %d AS name_index, %s AS candidate';
                array_push($args, $index, $candidate);
            }
        }
        $args[] = $taxonomy;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.name_index, MIN(t.term_id) AS term_id
             FROM (" . implode(' UNION ALL ', $candidates) . ") c
             INNER JOIN $wpdb->terms t ON t.name = c.candidate
             INNER JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
             GROUP BY c.name_index",
            $args
        )) ?: [];
        $found = [];
        foreach ($rows as $row) {
            $found[$names[(int)$row->name_index]] = (int)$row->term_id;
        }
        return $found;
    }

    /**
     * Each term's `Parent>Child` path, the form a hierarchical term value takes in the sheet. A flat
     * taxonomy's path is the name. Ancestors load one query per level, for all the terms at once.
     *
     * @param \WP_Taxonomy|object $taxonomy
     * @param object[] $rows term_id, name and parent of each term
     * @return array<int, string> keyed by term id
     */
    private function paths($taxonomy, array $rows): array
    {
        $known = [];
        foreach ($rows as $row) {
            $known[(int)$row->term_id] = $row;
        }
        if ($taxonomy->hierarchical) {
            $missing = $this->missing_parents($known);
            while (!empty($missing)) {
                foreach ($this->load_terms($taxonomy->name, $missing) as $term_id => $row) {
                    $known[$term_id] = $row;
                }
                // A parent that does not load is left out of the path rather than asked for again.
                foreach ($missing as $term_id) {
                    $known[$term_id] = $known[$term_id] ?? null;
                }
                $missing = $this->missing_parents($known);
            }
        }

        $paths = [];
        foreach ($rows as $row) {
            $names = [(string)$row->name];
            $seen = [(int)$row->term_id => true];
            $parent = $taxonomy->hierarchical ? (int)$row->parent : 0;
            while ($parent > 0 && !isset($seen[$parent]) && !empty($known[$parent])) {
                array_unshift($names, (string)$known[$parent]->name);
                $seen[$parent] = true;
                $parent = (int)$known[$parent]->parent;
            }
            $paths[(int)$row->term_id] = implode('>', $names);
        }
        return $paths;
    }

    /**
     * @param array<int, object|null> $known
     * @return int[] parents not loaded yet
     */
    private function missing_parents(array $known): array
    {
        $missing = [];
        foreach ($known as $row) {
            $parent = $row ? (int)$row->parent : 0;
            if ($parent > 0 && !array_key_exists($parent, $known)) {
                $missing[$parent] = $parent;
            }
        }
        return array_values($missing);
    }

    /**
     * @param int[] $term_ids
     * @return array<int, object> term_id, name and parent, keyed by term id
     */
    private function load_terms(string $taxonomy, array $term_ids): array
    {
        global $wpdb;
        if (empty($term_ids)) {
            return [];
        }
        $in_ids = implode(', ', array_fill(0, count($term_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, tt.parent FROM $wpdb->terms t
             INNER JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = %s AND t.term_id IN ($in_ids)",
            array_merge([$taxonomy], $term_ids)
        )) ?: [];
        $terms = [];
        foreach ($rows as $row) {
            $terms[(int)$row->term_id] = $row;
        }
        return $terms;
    }
}
