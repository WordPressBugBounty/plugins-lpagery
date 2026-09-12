<?php

namespace LPagery\data\repository;

use Exception;

/**
 * Data access for Views (related-pages display configurations, ADR-0003) and the
 * resolver reads that back the [lpagery_view] render path.
 *
 * The one focused seam for the View/resolver SQL. This is plain FREE code with no premium gate: the resolver reads sit on the
 * free render path (ADR-0002/0005), so nothing here may touch `lpagery_fs()` or premium-only
 * symbols. Stateless — each method reaches for `global $wpdb`; there is no constructor state.
 *
 * ## Normalized contract (differs from the legacy DAO shapes)
 *
 * - {@see find()} returns a single row object or `null` when the View is missing (never an
 *   empty array).
 * - {@see all()} returns an array of row objects; the `config` column is left as the raw
 *   stored JSON string (decoded by {@see \LPagery\model\view\ViewConfig::from_row}, so decoding
 *   here would risk JSON re-encode byte-drift).
 * - The three resolver-row reads ({@see rows_for_matching()}, {@see rows_for_all_pages()},
 *   {@see sort_keys_for()}) return rows whose `data` field is ALREADY UNSERIALIZED (a PHP array,
 *   via `maybe_unserialize`), with the scalar fields normalized to their natural types
 *   (`post_id` int, `created` string, `menu_order` int). The View resolver consumes these
 *   normalized rows directly.
 */
class ViewRepository
{
    /**
     * Persist a new View for a Process. Returns the new View id.
     */
    public function create(int $process_id, string $name, string $match_key, string $mode, array $config): int
    {
        global $wpdb;
        $table_name_view = $wpdb->prefix . 'lpagery_view';

        $wpdb->insert($table_name_view, array(
            "process_id" => $process_id,
            "name" => $name,
            "match_key" => $match_key,
            "mode" => $mode,
            "config" => wp_json_encode($config),
            "created" => current_time('mysql'),
            "modified" => current_time('mysql'),
        ));

        if (!$wpdb->insert_id || $wpdb->last_error) {
            throw new Exception("Failed to create view for process $process_id " . $wpdb->last_error);
        }

        return (int)$wpdb->insert_id;
    }

    /**
     * Update an existing View's name, match key, mode, and config (slot mappings / ordering / limit).
     */
    public function update(int $view_id, string $name, string $match_key, string $mode, array $config): void
    {
        global $wpdb;
        $table_name_view = $wpdb->prefix . 'lpagery_view';

        $wpdb->update($table_name_view, array(
            "name" => $name,
            "match_key" => $match_key,
            "mode" => $mode,
            "config" => wp_json_encode($config),
            "modified" => current_time('mysql'),
        ), array("id" => $view_id));

        if ($wpdb->last_error) {
            throw new Exception("Failed to update view $view_id " . $wpdb->last_error);
        }
    }

    /**
     * Delete a single View row by id.
     */
    public function delete(int $view_id): void
    {
        global $wpdb;
        $table_name_view = $wpdb->prefix . 'lpagery_view';

        $wpdb->delete($table_name_view, array("id" => $view_id));

        if ($wpdb->last_error) {
            throw new Exception("Failed to delete view $view_id " . $wpdb->last_error);
        }
    }

    /**
     * Delete every View owned by a Process. Views are owned by the Process (ADR-0003), so they are
     * dropped with it — deleting a Page Set must never leave orphaned View rows pointing at a parent
     * that no longer exists. Called first by {@see \LPagery\service\delete\DeleteProcessService}
     * (the historical order: view → generated pages + meta → sync queue → process).
     */
    public function delete_by_process(int $process_id): void
    {
        global $wpdb;
        $table_name_view = $wpdb->prefix . 'lpagery_view';

        $wpdb->delete($table_name_view, array("process_id" => $process_id));

        if ($wpdb->last_error) {
            throw new Exception("Failed to delete views for process $process_id " . $wpdb->last_error);
        }
    }

    /**
     * Load a single View row by id, or null if it does not exist. The `config` column is left as
     * the raw stored JSON string.
     */
    public function find(int $view_id): ?object
    {
        global $wpdb;
        $table_name_view = $wpdb->prefix . 'lpagery_view';

        $prepare = $wpdb->prepare("select id, process_id, name, match_key, mode, config, created, modified
                from $table_name_view where id = %d", $view_id);
        $results = $wpdb->get_results($prepare);

        return empty($results) ? null : $results[0];
    }

    /**
     * Every View across all Page Sets (for the global Views list), joined with its parent Process so
     * each row carries the fields needed to compute the Page Set's `purpose_with_name` label:
     * `process_purpose`, `process_post_id` (template id -> post-type fallback) and `process_created`.
     * The View columns keep their own names so {@see \LPagery\model\view\ViewConfig::from_row} still
     * maps a row unchanged, and the `config` column stays the raw stored JSON string. Ordered by Page
     * Set then View id for a stable grouping.
     *
     * @return array<int, object> rows of { id, process_id, name, match_key, mode, config,
     *                                       process_purpose, process_post_id, process_created }
     */
    public function all(): array
    {
        global $wpdb;
        $table_name_view = $wpdb->prefix . 'lpagery_view';
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        return $wpdb->get_results("select v.id as id, v.process_id as process_id, v.name as name,
                    v.match_key as match_key, v.mode as mode, v.config as config,
                    p.purpose as process_purpose, p.post_id as process_post_id, p.created as process_created
                from $table_name_view v
                inner join $table_name_process p on p.id = v.process_id
                order by v.process_id, v.id");
    }

    /**
     * Live-fallback read used by the View resolver before/instead of the meta index:
     * returns one row per non-trashed Generated Page of a Process, with the placeholder `data`
     * unserialized so the resolver can match in PHP. Scoped to the single Process so the scan is
     * bounded by that Process's page count.
     *
     * @return array<int, object> rows of { post_id, data, created, menu_order } (data unserialized)
     */
    public function rows_for_matching(int $process_id): array
    {
        global $wpdb;

        $prepare = $wpdb->prepare("select " . $this->resolver_select_columns() . " "
            . $this->resolver_from_where() . "
                order by lpp.post_id", $process_id);

        return $this->normalize_resolver_rows($this->as_rows($wpdb->get_results($prepare)));
    }

    /**
     * All-pages resolver read (ADR-0005): the Process's Generated Pages, capped at $limit and
     * ordered `created ASC` so the cap selects a stable, oldest-first window. Carries the same
     * columns as {@see rows_for_matching()} so the all-pages path builds identical candidates.
     *
     * @return array<int, object> rows of { post_id, data, created, menu_order } (data unserialized)
     */
    public function rows_for_all_pages(int $process_id, int $limit): array
    {
        global $wpdb;

        $limit = max(1, $limit);
        $prepare = $wpdb->prepare("select " . $this->resolver_select_columns() . " "
            . $this->resolver_from_where() . "
                order by lpp.created asc, lpp.post_id asc
                limit %d", $process_id, $limit);

        return $this->normalize_resolver_rows($this->as_rows($wpdb->get_results($prepare)));
    }

    /**
     * Count the Process's resolvable Generated Pages (same scope as the resolver reads). The
     * all-pages controller path compares this against {@see \LPagery\model\view\ViewConfig::ALL_PAGES_CAP}
     * to decide whether to surface the admin-only over-cap diagnostic (ADR-0005).
     */
    public function count_for_resolver(int $process_id): int
    {
        global $wpdb;

        $prepare = $wpdb->prepare("select count(*) " . $this->resolver_from_where(), $process_id);

        return (int)$wpdb->get_var($prepare);
    }

    /**
     * Sort-key read for the index-backed resolver path (slice #165). Given the matched post ids,
     * load the keys ordering needs — `data` (for label / custom-field ordering), `created`, and WP
     * `menu_order` — for exactly those ids, so the index path orders identically to the live path.
     *
     * @param int[] $post_ids
     * @return array<int, object> rows of { post_id, data, created, menu_order } (data unserialized)
     */
    public function sort_keys_for(array $post_ids): array
    {
        if (empty($post_ids)) {
            return array();
        }

        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $ids = array_map('intval', $post_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $prepare = $wpdb->prepare(
            "select " . $this->resolver_select_columns() . "
                from $table_name_process_post lpp
                inner join $wpdb->posts p on p.id = lpp.post_id
                where lpp.post_id in ($placeholders)",
            $ids);

        return $this->normalize_resolver_rows($this->as_rows($wpdb->get_results($prepare)));
    }

    /**
     * The shared SELECT column list for the resolver-row reads (live, all-pages, and sort-key).
     * Kept in one place so all three project the identical row shape.
     */
    private function resolver_select_columns(): string
    {
        return "lpp.post_id as post_id, lpp.data as data, lpp.created as created, p.menu_order as menu_order";
    }

    /**
     * The shared FROM/JOIN/WHERE core of the process-scoped resolver reads
     * ({@see rows_for_matching()}, {@see rows_for_all_pages()}, {@see count_for_resolver()}). Carries
     * the single `lpagery_process_id = %d` placeholder and the linkability filter.
     *
     * Linkability is `publish` only: a View renders links a visitor follows, and a draft, pending,
     * scheduled, private, trashed or auto-draft sibling resolves to a `?page_id=N` they cannot open.
     * Private pages are excluded on purpose — showing them would make View output viewer-dependent
     * and interact badly with page caching.
     */
    private function resolver_from_where(): string
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        return "from $table_name_process_post lpp
                inner join $wpdb->posts p on p.id = lpp.post_id
                where lpp.lpagery_process_id = %d and p.post_status = 'publish'";
    }

    /**
     * Normalize raw resolver rows to the repository contract: `data` unserialized to a PHP array,
     * `post_id`/`menu_order` cast to int and `created` to string.
     *
     * @param array<int, \stdClass> $rows
     * @return array<int, \stdClass>
     */
    private function normalize_resolver_rows(array $rows): array
    {
        $normalized = array();
        foreach ($rows as $row) {
            $out = new \stdClass();
            $out->post_id = (int)$row->post_id;
            $out->data = maybe_unserialize($row->data);
            $out->created = (string)$row->created;
            $out->menu_order = (int)$row->menu_order;
            $normalized[] = $out;
        }
        return $normalized;
    }

    /**
     * Coerce a `$wpdb->get_results()` result (which is `null` on error) to an array so it can be
     * iterated safely. Never changes the row objects.
     *
     * @param mixed $results
     * @return array<int, \stdClass>
     */
    private function as_rows($results): array
    {
        return is_array($results) ? $results : array();
    }
}
