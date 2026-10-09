<?php

namespace LPagery\data\repository;

use Exception;
use LPagery\model\Params;
use LPagery\multilingual\MultilingualPlugin;
use LPagery\service\image_endpoint\VirtualImageMap;

/**
 * Data access for Generated Pages (`lpagery_process_post` — the per-page tracking rows that tie a
 * WordPress post to its Page Set, ADR naming: a "Generated Page") plus the pure `wp_posts` lookups
 * the create/update/slug flows lean on.
 *
 * The one focused seam for the process_post tracking, slug matching and post-lookup SQL, including
 * the Live Mode reads/writes.
 *
 * ## One-way index maintenance (breaks the old DAO↔ViewMetaIndexService cycle)
 *
 * This repository depends one-way on {@see PageMetaIndexRepository}. The single page-write choke
 * point {@see add_post_to_process()} funnels every manual generation, Google Sheet sync and update
 * through {@see PageMetaIndexRepository::sync_page()}; the delete choke points
 * ({@see delete_post()}, {@see delete_by_process()}) drop the matching meta rows. This is the only
 * page-write index-maintenance path and it cannot be forgotten.
 *
 * ## Cached "template pending changes" answer
 *
 * {@see has_template_pending_changes()} memoizes its per-template answer in a private instance array
 * (replacing the legacy method-local `static` cache). Every write method on this repository resets
 * that cache so a subsequent read reflects the new state. Behind the memo the answer costs two
 * constant-time reads — the template's `post_modified`, then a `LIMIT 1` probe for the first stale
 * Generated Page — rather than a count over the whole Page Set (#278).
 *
 * ## Cached Generated Page counts
 *
 * {@see get_generated_page_counts_by_status()} answers the "Hide generated pages" post-count
 * adjustment from a per-request memo backed by the `lpagery_generated_page_counts` transient,
 * instead of re-running the `COUNT(DISTINCT ...)` join on every `wp_count_posts` call (#275).
 *
 * ## Persisted "any live page exists" answer
 *
 * {@see live_stubs_exist()} answers the render pipeline's site-wide Live Mode gate from the
 * autoloaded {@see LIVE_PAGES_EXIST_OPTION} option, so a front-end request on a site without Live
 * Mode runs no query for the gate at all (#281). The answer carries the {@see LIVE_PAGES_VERSION_OPTION}
 * token it was computed under, and {@see invalidate_live_pages_exist()} bumps that token, so an
 * answer computed before a write is never trusted after it.
 *
 * All three caches are dropped through the one private {@see reset_caches_after_write()} helper that
 * every write method calls, so none can be forgotten when a write path is added. The Render Mode
 * marker write ({@see set_render_mode()}) drops the live-pages answer on its own.
 *
 * ## One write path for the Render Mode marker
 *
 * {@see set_render_mode()} is the only place that writes a page's `_lpagery_render_mode` marker
 * (#280). Generation/update and the Classic → Live strip both go through it, so anything that has
 * to react to a Render Mode change hooks one method instead of every call site.
 */
class GeneratedPageRepository
{
    /**
     * Cap on how many ids go into a single revision/postmeta `DELETE ... IN (...)` statement, so a
     * large install's purge stays bounded (packet size, row-lock span) and a mid-run failure orphans
     * at most one chunk. See {@see delete_revisions_for_generated_pages()}.
     */
    private const DELETE_REVISIONS_CHUNK_SIZE = 500;

    /**
     * Cap on how many values go into one Pre-flight Check `IN (...)` lookup, so a sheet with tens
     * of thousands of rows costs a bounded number of queries of a bounded size.
     */
    private const SLUG_CHUNK_SIZE = 1000;

    /**
     * Cross-request store of {@see get_generated_page_counts_by_status()}, holding
     * `post_type => [post_status => count]`. A transient rather than an option so it is never
     * autoloaded and routes through the object cache when the site has one.
     */
    public const COUNTS_TRANSIENT_KEY = 'lpagery_generated_page_counts';

    /**
     * Cross-request store of {@see live_stubs_exist()} — `'1'`, `'0'`, or absent for "not answered
     * yet". An autoloaded option rather than a transient: an expiring transient is not autoloaded,
     * so reading it would cost the very query this store removes (issue #281).
     */
    public const LIVE_PAGES_EXIST_OPTION = 'lpagery_live_pages_exist';

    /**
     * Autoloaded publication token for {@see LIVE_PAGES_EXIST_OPTION}: an opaque string that every
     * invalidation replaces. A reader captures it before running the EXISTS query and stores it with
     * the answer; the answer is only trusted while the token is unchanged. This is what makes the
     * store safe under concurrency: a request that queried before a marker turned live cannot
     * publish a "no live page" answer that outlives that write, because the write bumped the token
     * in between (issue #281, review).
     */
    public const LIVE_PAGES_VERSION_OPTION = 'lpagery_live_pages_version';

    private PageMetaIndexRepository $pageMetaIndexRepository;

    /** The active Multilingual Plugin, or null when none is; only the slug lookup needs it. */
    private ?MultilingualPlugin $multilingualPlugin;

    /**
     * Per-request memoization of {@see has_template_pending_changes()}, keyed by template id.
     * Reset by every write method on this repository.
     *
     * @var array<int|string, bool>
     */
    private array $pending_changes_cache = array();

    /**
     * Per-request memoization of {@see get_generated_page_counts_by_status()}, keyed by post type.
     * Reset by every write method on this repository.
     *
     * @var array<string, array<string, int>>
     */
    private array $generated_page_counts_cache = array();

    /**
     * Whether the counts transient has already been deleted since it was last stored. A single
     * generation run calls the write methods once per page, and every one of them invalidates;
     * this flag keeps that at one `delete_transient()` per request instead of 30k.
     */
    private bool $counts_transient_deleted = false;

    /**
     * Whether the "any live page exists" token has already been bumped since the answer was last
     * stored. A generation run calls the write methods once per page, so this keeps the invalidation
     * at one `update_option()` per request instead of one per Generated Page.
     */
    private bool $live_pages_version_bumped = false;

    public function __construct(PageMetaIndexRepository $pageMetaIndexRepository, ?MultilingualPlugin $multilingualPlugin = null)
    {
        $this->pageMetaIndexRepository = $pageMetaIndexRepository;
        $this->multilingualPlugin = $multilingualPlugin;
    }

    /**
     * The site's Generated Page counts for one post type, split by post status — the subtrahend of
     * the "Hide generated pages" `wp_count_posts` adjustment (issue #275).
     *
     * `wp_count_posts` fires several times per admin request (At a Glance, list-table views, other
     * plugins), and the underlying `COUNT(DISTINCT ...)` join costs tens of milliseconds on a site
     * with tens of thousands of Generated Pages. So the answer is memoized per request on this
     * instance (the root shares one repository per request) and stored across requests in the
     * {@see COUNTS_TRANSIENT_KEY} transient. Every repository write drops both
     * ({@see invalidate_generated_page_counts()}), so a generation, deletion, conversion or reset
     * is reflected on the next read.
     *
     * @return array<string, int> post_status => count; statuses with no Generated Page are absent
     */
    public function get_generated_page_counts_by_status(string $post_type): array
    {
        if (isset($this->generated_page_counts_cache[$post_type])) {
            return $this->generated_page_counts_cache[$post_type];
        }

        $stored = get_transient(self::COUNTS_TRANSIENT_KEY);
        $stored = is_array($stored) ? $stored : array();

        if (isset($stored[$post_type]) && is_array($stored[$post_type])) {
            $this->generated_page_counts_cache[$post_type] = $stored[$post_type];

            return $stored[$post_type];
        }

        $counts = $this->query_generated_page_counts_by_status($post_type);

        $this->generated_page_counts_cache[$post_type] = $counts;
        $stored[$post_type] = $counts;
        set_transient(self::COUNTS_TRANSIENT_KEY, $stored, DAY_IN_SECONDS);
        // The transient now holds fresh data again, so the next write must delete it once more.
        $this->counts_transient_deleted = false;

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function query_generated_page_counts_by_status(string $post_type): array
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        // COUNT(DISTINCT ...) so a page tracked by more than one Page Set is subtracted once.
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT p.post_status, COUNT(DISTINCT p.ID) as count
            FROM $wpdb->posts p
            INNER JOIN $table_name_process_post lpp ON p.ID = lpp.post_id
            WHERE p.post_type = %s
            GROUP BY p.post_status
        ", $post_type), ARRAY_A);

        $counts = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $counts[(string)$row['post_status']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Drop the memoized and stored Generated Page counts, so the next read re-queries.
     *
     * Called from every write method on this repository and from the WordPress post-status hooks in
     * {@see \LPagery\io\hooks\AdminListTableHooks}, which catch the status changes made outside
     * LPagery (someone trashing a Generated Page by hand).
     */
    public function invalidate_generated_page_counts(): void
    {
        $this->generated_page_counts_cache = array();

        if ($this->counts_transient_deleted) {
            return;
        }

        delete_transient(self::COUNTS_TRANSIENT_KEY);
        $this->counts_transient_deleted = true;
    }

    /**
     * Retire the persisted "does any Live Mode page exist?" answer by replacing its publication
     * token, so the next {@see live_stubs_exist()} re-queries and stores it again. The answer itself
     * is left in place: a reader that captured the old token before this write may still store it,
     * and the token mismatch is exactly what stops that answer from being trusted.
     *
     * Called from {@see set_render_mode()} (a page becomes live or classic) and from
     * {@see reset_caches_after_write()} (a Generated Page or a whole Page Set is deleted, including
     * the plugin reset, which deletes every Page Set through {@see delete_by_process()}). The token
     * is bumped at most once per request between stores, so a 30k-page generation run costs one
     * `update_option()` rather than 30k. The one exception is a write that makes a page live, which
     * {@see set_render_mode()} re-arms before calling here (see the note there).
     */
    public function invalidate_live_pages_exist(): void
    {
        if ($this->live_pages_version_bumped) {
            return;
        }

        // Opaque and unique per call: `update_option()` skips an unchanged value, and a counter would
        // need a read first. Autoloaded so readers get it for free.
        update_option(self::LIVE_PAGES_VERSION_OPTION, uniqid('', true), true);
        $this->live_pages_version_bumped = true;
    }

    /**
     * The one write-path cache reset: drops the memoized has-template-pending-changes answers, the
     * Generated Page counts and the persisted "any live page exists" answer. Every write method
     * calls this, so none of the three can be forgotten.
     */
    private function reset_caches_after_write(): void
    {
        $this->pending_changes_cache = array();
        $this->invalidate_generated_page_counts();
        $this->invalidate_live_pages_exist();
    }

    /**
     * Whether any Generated Page of this Template Page is older than the template's last edit — the
     * question behind the "Apply Template Changes" button and its admin bar item.
     *
     * Two constant-cost reads instead of a count over the whole Page Set (issue #278): the
     * template's own `post_modified`, then a probe that stops at the FIRST stale Generated Page.
     * The probe's range condition rides the `process_post_template_modified (template_id, modified)`
     * index (migrator v23), so the answer no longer grows with the size of the set. Semantics are
     * unchanged: trashed pages are ignored and a NULL `modified` never counts as pending, exactly as
     * `source_post.post_modified > lpp.modified` never matched one.
     *
     * @param int|string $id the Template Page's post id
     * @return bool
     */
    public function has_template_pending_changes($id)
    {
        global $wpdb;

        // Instance cache to avoid repeated queries for the same template within a request.
        if (isset($this->pending_changes_cache[$id])) {
            return $this->pending_changes_cache[$id];
        }

        // Tables are guaranteed to exist after admin_init, no need to check
        $template_modified = $wpdb->get_var($wpdb->prepare(
            "SELECT post_modified FROM $wpdb->posts WHERE ID = %d", $id));
        if (!is_string($template_modified) || trim($template_modified) === '') {
            // No such template post — nothing can be pending against it.
            $this->pending_changes_cache[$id] = false;
            return false;
        }

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $first_match = $wpdb->get_var($wpdb->prepare("SELECT 1
                FROM $table_name_process_post lpp
                         INNER JOIN $wpdb->posts p ON p.ID = lpp.post_id
                WHERE lpp.template_id = %d
                  AND lpp.modified < %s
                  AND p.post_status <> 'trash'
                LIMIT 1", $id, $template_modified));

        $has_changes = $first_match !== null;
        $this->pending_changes_cache[$id] = $has_changes;

        return $has_changes;
    }

    /**
     * Whether this Page Set already has a Generated Page whose stored payload hash matches — the
     * sync dedup check. Replaces the sheet-sync handler's raw `$wpdb->query()` on a SELECT (which
     * leaned on the returned row count) with an honest existence query.
     */
    public function exists_by_hashed_payload(int $process_id, string $hash): bool
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name_process_post WHERE hashed_payload = %s AND lpagery_process_id = %d",
            $hash, $process_id));
        return intval($count) > 0;
    }

    public function get_existing_posts_for_update_modal($template_id, $process_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        if (!$template_id && !$process_id) {
            throw new Exception("Template ID or Process ID is required");
        }

        $query = "SELECT p.ID,
                     p.post_parent as parent_id,
                     p.post_status as post_status,
                     lpp.lpagery_process_id AS process_id,
                     lpp.template_id as template_id,
                     p.post_title,
                     p.post_name,
                     p.post_type,
                    lpp.page_manually_updated_at,
                    lpp.page_manually_updated_by,
                    lpp.replaced_slug AS replaced_slug,
                    lpp.parent_search_term AS parent_search_term


              FROM {$wpdb->posts} p
              INNER JOIN {$table_name_process_post} lpp ON p.ID = lpp.post_id
              WHERE p.post_status != 'trash'";

        if ($process_id) {
            $query .= " AND lpp.lpagery_process_id = %s GROUP BY p.ID ORDER BY p.ID";
            $prepare = $wpdb->prepare($query, $process_id, $process_id);
        } else {
            $query .= " AND lpp.template_id = %s GROUP BY p.ID ORDER BY p.ID";
            $prepare = $wpdb->prepare($query, $template_id);
        }

        $results = $wpdb->get_results($prepare);

        return $results;
    }

    /**
     * The single page-write choke point: insert or update the Generated Page's `lpagery_process_post`
     * row, then keep the sparse meta index fresh for the Process's currently-Indexed keys via the
     * one-way {@see PageMetaIndexRepository::sync_page()} path.
     *
     * `$wpdb->suppress_errors` is saved and restored via try/finally so the throws inside can never
     * leak the suppressed state to later queries. The early duplicate-slug return does NOT sync the
     * index (nothing was written); the normal return reads `$wpdb->insert_id` for `created_id` AFTER
     * the index sync (matching the legacy behaviour).
     */
    public function add_post_to_process(Params $params, $post_id, $template_id, $replaced_slug, $shouldContentBeUpdated, $parent_id, $parent_search_term, $client_generated_slug, $hashed_payload)
    {
        global $wpdb;

        $original = $wpdb->suppress_errors;
        $wpdb->suppress_errors = true;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $process_id = $params->process_id;
        $sanitized_slug = sanitize_title($replaced_slug);

        try {
            $prepare = $wpdb->prepare("select lpp.id from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = %s and lpp.replaced_slug = %s and lpp.post_id != %s and p.post_parent = %s",
                $process_id, $sanitized_slug, $post_id, $parent_id);
            $existing_process_post_with_another_id = $wpdb->get_results($prepare);
            if ($existing_process_post_with_another_id) {
                return array("created_id" => null,
                    "error" => "Post with the same slug $sanitized_slug already exists in process $process_id ");
            }

            $process_data = $wpdb->get_results($wpdb->prepare("SELECT data FROM $table_name_process where id = %s",
                $process_id));

            $spintax_enabled = $params->spintax_enabled;
            $image_processing_enabled = $params->image_processing_enabled;
            $process_config = !empty($process_data) ? $process_data[0]->data : null;
            $lpagery_settings = serialize(array("spintax_enabled" => $spintax_enabled,
                "image_processing_enabled" => $image_processing_enabled));

            // Persist the per-page image pairs so live renders can derive per-page image maps from
            // current attachment state (ADR 0010/0013). Two coexisting channels: the legacy
            // source→target duplicated-attachment pairs, and the Virtual Image Map (copy-form values in
            // Live Mode — source id + substituted filename, no copy). Only set when at least one channel
            // is present, so a later config-only update never wipes stored pairs.
            $attachment_id_pairs = null;
            $pairs_to_store = array();
            if (!empty($params->source_attachment_ids) && !empty($params->target_attachment_ids)) {
                $pairs_to_store["source"] = $params->source_attachment_ids;
                $pairs_to_store["target"] = $params->target_attachment_ids;
            }
            if (!empty($params->virtual_image_map)) {
                $pairs_to_store[VirtualImageMap::KEY] = $params->virtual_image_map;
            }
            if (!empty($pairs_to_store)) {
                $attachment_id_pairs = serialize($pairs_to_store);
            }

            $prepare = $wpdb->prepare("select lpp.id from $table_name_process_post lpp where lpp.lpagery_process_id = %s and lpp.post_id = %s",
                $process_id, $post_id);
            $process_post_already_exists = $wpdb->get_results($prepare);
            if ($process_post_already_exists) {
                $update_array = array("data" => serialize($params->raw_data),
                    "replaced_slug" => $sanitized_slug,
                    "config" => $process_config,
                    "lpagery_settings" => $lpagery_settings,
                    "parent_search_term" => $parent_search_term,
                    "template_id" => $template_id,
                    "modified" => current_time('mysql'));
                if ($shouldContentBeUpdated) {
                    $update_array["page_manually_updated_at"] = null;
                    $update_array["page_manually_updated_by"] = null;
                }
                if($client_generated_slug) {
                    $update_array["client_generated_slug"] = $client_generated_slug;
                }
                if($hashed_payload) {
                    $update_array["hashed_payload"] = $hashed_payload;
                }
                if ($attachment_id_pairs !== null) {
                    $update_array["attachment_id_pairs"] = $attachment_id_pairs;
                }
                $update_result = $wpdb->update($table_name_process_post, $update_array, array("post_id" => $post_id,
                    "lpagery_process_id" => $process_id));

                if ($update_result === false) {
                    throw new Exception("Failed to update post $post_id in process $process_id " . $wpdb->last_error);
                }
            } else {
                $wpdb->insert($table_name_process_post, array("post_id" => $post_id,
                    "lpagery_process_id" => $process_id,
                    "data" => serialize($params->raw_data),
                    "created" => current_time('mysql'),
                    "replaced_slug" => $sanitized_slug,
                    "config" => $process_config,
                    "parent_search_term" => $parent_search_term,
                    "client_generated_slug" => $client_generated_slug,
                    "lpagery_settings" => $lpagery_settings,
                    "template_id" => $template_id,
                    "hashed_payload" => $hashed_payload,
                    "attachment_id_pairs" => $attachment_id_pairs,
                    // Insert-once (ADR 0017): the page's Spin Seed is written when its row is born and
                    // never again, so every later template re-application resolves the same spintax
                    // options. The UPDATE branch above deliberately omits the column.
                    "spin_seed" => $params->spin_seed,
                    "modified" => current_time('mysql')));
                if (!$wpdb->insert_id || $wpdb->last_error) {
                    throw new Exception("Failed to add post $post_id to process $process_id " . $wpdb->last_error);
                }
            }
        } finally {
            $wpdb->suppress_errors = $original;
        }

        // Write path: drop the memoized has-template-pending-changes answers and page counts.
        $this->reset_caches_after_write();

        // Keep the sparse meta index fresh for this Process's currently-Indexed keys.
        // Funnelling through here means manual generation, Google Sheet sync, and updates
        // all maintain the index via the same choke point (ADR-0001).
        $this->pageMetaIndexRepository->sync_page($process_id, (int)$post_id, is_array($params->raw_data) ? $params->raw_data : array());

        return array("created_id" => $wpdb->insert_id,
            "error" => null);
    }

    public function update_process_post_data($process_id, $data, $post_id, $slug, $replaced_slug)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->update($table_name_process_post, array("data" => serialize($data),
            "replaced_slug" => $replaced_slug,
            "modified" => current_time('mysql')), array("post_id" => $post_id,
            "lpagery_process_id" => $process_id));

        $this->reset_caches_after_write();

        return $wpdb->insert_id;
    }

    public function update_process_modified($post_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->update($table_name_process_post, array("modified" => current_time('mysql')),
            array("post_id" => $post_id));

        $this->reset_caches_after_write();

        return $wpdb->insert_id;
    }

    public function get_process_post_input_data($process_id, $download = false)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.id, post_id, data
				from $table_name_process_post lpp
				inner join $wpdb->posts p on p.id = lpp.post_id
				where lpagery_process_id = %s and p.post_status != 'trash' order by lpp.post_id", $process_id);
        $results = $wpdb->get_results($prepare);
        if ($download) {
            $results = array_map(function ($value) {
                $permalink = get_permalink($value->post_id);
                $value->permalink = $permalink;
                return $value;
            }, $results);

        }
        return $results;
    }

    public function get_process_post_data($generated_post_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.data as data, lpp.template_id as source_id, lpp.lpagery_process_id as process_id, lpp.modified as modified,  lpp.page_manually_updated_at,  lpp.page_manually_updated_by
				from $table_name_process_post lpp
				inner join $wpdb->posts p on p.id = lpp.post_id
				where lpp.post_id = %s and p.post_status != 'trash' order by lpp.id", $generated_post_id);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return array();
        }
        return $results [0];
    }

    /**
     * The Generated Page's stored Spin Seed, or null when the row has none (ADR 0017).
     *
     * Read on the update path so a re-applied template resolves the page's original spintax picks
     * instead of drawing new ones. Rows predating the seed are backfilled by migration v21, so null
     * here means "no tracking row for this page in this Page Set" and the caller falls back to the
     * unseeded behaviour.
     */
    public function get_spin_seed(int $process_id, int $generated_post_id): ?int
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("SELECT spin_seed FROM $table_name_process_post WHERE post_id = %d and lpagery_process_id = %d order by id limit 1",
            $generated_post_id, $process_id);
        $spin_seed = $wpdb->get_var($prepare);
        // get_var() answers null for both "no seed" and "query failed"; only last_error tells them
        // apart. A failed read must not silently degrade the page to unseeded (random) wording.
        if ($wpdb->last_error) {
            throw new Exception("Failed to read spin seed of post $generated_post_id in process $process_id " . $wpdb->last_error);
        }

        return $spin_seed === null ? null : (int)$spin_seed;
    }

    /**
     * Stamp a Manual Change on the generated page's `lpagery_process_post` row (ADR 0010). Keyed on
     * post id and render-mode-agnostic, so it records hand edits of both classic pages and Live
     * Mode stubs (e.g. quick-editing a stub's title/slug/status). No-ops for a post that has no row.
     */
    public function stamp_manual_change(int $post_id, int $user_id): void
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name_process_post SET page_manually_updated_at = %s, page_manually_updated_by = %d WHERE post_id = %d",
            current_time('mysql'),
            $user_id,
            $post_id
        ));

        $this->reset_caches_after_write();
    }

    public function get_posts_by_process($process_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("select p.id, p.post_type, p.post_title, p.post_name
					from $wpdb->posts p
         inner join $table_name_process_post lpp on p.ID = lpp.post_id where lpp.lpagery_process_id = %s and p.post_status != 'trash'",
            $process_id);

        return $wpdb->get_results($prepare);
    }

    /**
     * The Page Set's *linkable* Generated Pages, backing the `[lpagery_urls]` listing.
     *
     * `publish` only: a draft, pending, scheduled, private or trashed page is linked as `?page_id=N`,
     * which a visitor cannot open. Kept separate from {@see get_posts_by_process()}, which the Page
     * Set delete cascade and the admin listing use and which must keep seeing unpublished pages.
     *
     * @param int|string $process_id
     * @return array<int, object> rows of { id, post_type, post_title, post_name }
     */
    public function get_published_posts_by_process($process_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("select p.id, p.post_type, p.post_title, p.post_name
					from $wpdb->posts p
         inner join $table_name_process_post lpp on p.ID = lpp.post_id where lpp.lpagery_process_id = %s and p.post_status = 'publish'
         order by p.id",
            $process_id);

        return $wpdb->get_results($prepare);
    }

    /**
     * Delete a single Generated Page's tracking row and drop its meta index rows so trashed/deleted
     * pages fall out of View results (one-way cycle break — see class docblock).
     */
    public function delete_post($post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->delete($table_name_process_post, array("post_id" => $post_id));

        // Remove this page's meta index rows so trashed/deleted pages drop out of View results.
        $this->pageMetaIndexRepository->delete_post_meta((int)$post_id);

        $this->reset_caches_after_write();
    }

    /**
     * Delete every Generated Page tracking row of a Process and drop the Process's meta index rows,
     * so deleting a Page Set never leaves the sparse index drifting (one-way cycle break).
     */
    public function delete_by_process(int $process_id): void
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->delete($table_name_process_post, array("lpagery_process_id" => $process_id));

        // Drop the Process's meta index rows so the sparse index cannot drift.
        $this->pageMetaIndexRepository->delete_process_meta($process_id);

        $this->reset_caches_after_write();
    }

    /**
     * Purge every WordPress revision (and its postmeta) of the site's Generated Pages — the owner of
     * the "delete revisions" maintenance action, moved off the AJAX layer so the revision↔postmeta
     * schema relationship lives in one place. Collects the distinct Generated Page ids, then works in
     * bounded chunks: for each chunk it reads that chunk's revision ids, deletes the revisions, and
     * IMMEDIATELY deletes exactly those revisions' postmeta before advancing — so a mid-run failure
     * orphans at most one chunk's meta rows rather than the whole run, and no single `IN (...)`
     * statement can blow past `max_allowed_packet` or hold an unbounded row lock.
     *
     * Returns the number of revisions deleted.
     */
    public function delete_revisions_for_generated_pages(): int
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        // Every LPagery-generated post id (revisions hang off these as post_parent).
        $lpagery_post_ids = $wpdb->get_col("SELECT DISTINCT post_id FROM $table_name_process_post");
        if (empty($lpagery_post_ids)) {
            return 0;
        }

        $revisions_deleted = 0;
        foreach (array_chunk($lpagery_post_ids, self::DELETE_REVISIONS_CHUNK_SIZE) as $post_id_chunk) {
            $placeholders = implode(',', array_fill(0, count($post_id_chunk), '%d'));

            // The revision ids for this chunk, captured so we can drop exactly their postmeta rather
            // than a broad orphaned-postmeta sweep (which is an explicit non-goal).
            $revision_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM $wpdb->posts
                 WHERE post_type = 'revision'
                 AND post_parent IN ($placeholders)",
                $post_id_chunk
            ));

            // Delete this chunk's revisions first...
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $wpdb->posts
                 WHERE post_type = 'revision'
                 AND post_parent IN ($placeholders)",
                $post_id_chunk
            ));
            if ($deleted === false) {
                throw new Exception("Failed to delete revisions from database. " . $wpdb->last_error);
            }
            $revisions_deleted += (int)$deleted;

            // ...then IMMEDIATELY their postmeta (itself chunked so the delete list stays bounded),
            // so an interruption here leaves at most this one chunk's meta orphaned.
            foreach (array_chunk($revision_ids, self::DELETE_REVISIONS_CHUNK_SIZE) as $revision_id_chunk) {
                if (empty($revision_id_chunk)) {
                    continue;
                }
                $meta_placeholders = implode(',', array_fill(0, count($revision_id_chunk), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $wpdb->postmeta WHERE post_id IN ($meta_placeholders)",
                    $revision_id_chunk
                ));
            }
        }

        return $revisions_deleted;
    }

    public function find_post_by_name_and_type_equal($search_term, $post_type)
    {
        global $wpdb;
        $search_term = esc_sql($search_term);
        $search_term = strtolower($search_term);
        $prepare = $wpdb->prepare("select p.id as id, p.post_title as post_title
                from $wpdb->posts p where lower(post_name) = %s and post_type = %s and post_status in ('publish', 'draft', 'private')
            order by post_date
            limit 1;", $search_term, $post_type);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return (array)$results[0];
    }

    public function find_post_by_id($id)
    {
        $post_type = get_post_type($id);
        global $wpdb;
        $id = intval($id);

        $prepare = $wpdb->prepare("select p.id as id, p.post_title as post_title
              from $wpdb->posts p
              where p.id = %s
                and p.post_type = %s
                and p.post_status in ('private', 'draft', 'publish')", $id, $post_type);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }

        return (array)$results[0];
    }

    public function is_post_template_with_created_posts($post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select exists(select lpp.id
              from $table_name_process_post lpp
                       inner join $wpdb->posts p on p.ID = lpp.post_id
              where p.post_status != 'trash'
                and lpp.template_id = %s) as created_page_exists", $post_id);
        return filter_var($wpdb->get_results($prepare)[0]->created_page_exists, FILTER_VALIDATE_BOOLEAN);
    }

    public function get_process_id_by_template($post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("select lpagery_process_id as process_id
              from $table_name_process_post
              where template_id = %s limit 1", $post_id);
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return (array)$results[0];
    }

    public function get_existing_post_by_slug_in_process(int $process_id, string $slug, ?int $parent_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $parent_condition = $parent_id !== null ? "AND p.post_parent = %d" : "";
        // Argument order must match the placeholder order in the SQL below:
        // process_id (%s), then the optional parent (%d), then the slug (%s).
        // Appending the parent AFTER the slug previously bound the values to the
        // wrong placeholders (slug -> post_parent, parent -> replaced_slug), so
        // the lookup silently failed whenever a parent was passed (e.g. sheet
        // sync passes parent = 0), causing existing pages to never be matched.
        $query_params = array($process_id);
        if ($parent_id !== null) {
            $query_params[] = $parent_id;
        }
        $query_params[] = $slug;

        $prepare = $wpdb->prepare("select p.ID, post_title, lpagery_process_id as 'process_id', post_name,post_content_filtered,post_excerpt,post_content,post_status,post_parent,post_date, lpp.data as data, lpp.replaced_slug as replaced_slug, lpp.page_manually_updated_at, lpp.page_manually_updated_by
                    from $wpdb->posts p
                             inner join $table_name_process_post lpp on lpp.post_id = p.id
                    where lpp.lpagery_process_id = %s
                      $parent_condition
                    and (lpp.replaced_slug = %s) order by post_name", ...$query_params);
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            $prepare = $wpdb->prepare("select p.ID, post_title, lpagery_process_id as 'process_id', post_name,post_content_filtered,post_excerpt,post_content,post_status,post_parent,post_date, lpp.data as data, lpp.replaced_slug as replaced_slug, lpp.page_manually_updated_at, lpp.page_manually_updated_by
                    from $wpdb->posts p
                             inner join $table_name_process_post lpp on lpp.post_id = p.id
                    where lpp.lpagery_process_id = %s
                      $parent_condition
                    and (p.post_name = %s) order by post_name", ...$query_params);
            $results = $wpdb->get_results($prepare);
        }
        if (empty($results)) {
            return null;
        } else {
            return (array)$results[0];
        }
    }

    public function get_existing_post(string $slug, string $post_type, ?int $parent)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("SELECT p.ID, p.post_name, p.post_type, p.post_parent,  EXISTS (
                SELECT 1
                FROM $table_name_process_post lpp
                WHERE lpp.post_id = p.ID
            ) as managed_by_lpagery
            FROM $wpdb->posts p
            WHERE p.post_type = %s
            AND p.post_status NOT IN ('inherit', 'attachment')
            AND p.post_name = %s
            AND p.post_parent = %d", $post_type, $slug, $parent ?? 0);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return (array)$results[0];
    }

    public function get_existing_post_by_id_in_process(int $id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select p.ID, post_title, lpagery_process_id as 'process_id', post_name,post_content_filtered,post_excerpt,post_content,post_status,post_parent,post_date, lpp.replaced_slug as replaced_slug,  lpp.page_manually_updated_at,  lpp.page_manually_updated_by
                    from $wpdb->posts p
                             inner join $table_name_process_post lpp on lpp.post_id = p.id
                    where (p.id = %s) order by post_name", $id);
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        } else {
            return (array)$results[0];
        }
    }

    public function get_process_posts_slugs($process_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepared = $wpdb->prepare("select id, post_id, replaced_slug, client_generated_slug
            from $table_name_process_post where lpagery_process_id = %s", $process_id);
        $result = $wpdb->get_results($prepared);
        return $result;
    }

    public function get_process_config_changed($process_id, $post_id, $hashed_payload)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("SELECT data FROM $table_name_process WHERE id = %s", $process_id);
        $process_data = $wpdb->get_var($prepare);
        $prepare = $wpdb->prepare("SELECT config, hashed_payload FROM $table_name_process_post WHERE post_id = %s and lpagery_process_id = %s",
            $post_id, $process_id);
        $process_post_row = $wpdb->get_row($prepare, ARRAY_A);

        if (!$process_post_row) {
            return true;
        }

        $config_changed = ($process_data) !== ($process_post_row['config']);

        if ($hashed_payload && isset($process_post_row['hashed_payload'])) {
            $payload_changed = ($hashed_payload) !== ($process_post_row['hashed_payload']);
            return $config_changed || $payload_changed;
        }

        if ($hashed_payload && !isset($process_post_row['hashed_payload'])) {
            return true;
        }

        return $config_changed;

    }

    public function get_process_post_global_settings($process_id, $post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("SELECT lpagery_settings FROM $table_name_process_post WHERE post_id = %s and lpagery_process_id = %s",
            $post_id, $process_id);
        $lpagery_settings = $wpdb->get_var($prepare);
        return maybe_unserialize($lpagery_settings);

    }

    /**
     * The posts of `$post_type` whose slug is one of `$slugs`, for the Pre-flight Check. The Page
     * Set's own pages are left out, and so is every post outside the Template Page's Page Language:
     * a slug that exists only in another language is no clash. Queried in chunks of
     * {@see SLUG_CHUNK_SIZE}, so the query count stays bounded however many rows a sheet has.
     *
     * @param string[] $slugs
     * @return array<int, object> rows of { id, post_name, post_parent }
     */
    public function find_existing_posts_by_slugs(array $slugs, string $post_type, int $process_id, int $template_id): array
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        // Both the language and the SQL come from the Multilingual Plugin adapter.
        $language_join = '';
        $language_condition = '';
        $language_args = array();
        if ($this->multilingualPlugin !== null) {
            $template_language = $this->multilingualPlugin->get_post_language($template_id);
            if ($template_language) {
                $fragments = $this->multilingualPlugin->post_language_sql('p');
                $language_join = $fragments->join;
                $language_condition = 'AND ' . $fragments->where;
                $language_args[] = $template_language;
            }
        }

        $posts = array();
        foreach (array_chunk(array_values(array_unique($slugs)), self::SLUG_CHUNK_SIZE) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '%s'));
            // The language join carries no placeholder of its own, so only the language condition adds one.
            $query = "SELECT p.ID AS id, p.post_name, p.post_parent
                FROM $wpdb->posts p
                LEFT JOIN $table_name_process_post lpp
                    ON lpp.post_id = p.ID
                    AND lpp.lpagery_process_id = %d
                $language_join
                WHERE p.post_type = %s
                    AND p.post_status NOT IN ('inherit', 'attachment')
                    AND lpp.post_id IS NULL
                    AND p.post_name IN ($in)
                    $language_condition";
            $args = array_merge(array($process_id, $post_type), $chunk, $language_args);
            $posts = array_merge($posts, $wpdb->get_results($wpdb->prepare($query, $args)) ?: array());
        }
        return $posts;
    }

    /**
     * Which of `$post_ids` belong to a Page Set other than `$process_id`.
     *
     * @param int[] $post_ids
     * @return int[]
     */
    public function find_post_ids_in_other_page_sets(array $post_ids, int $process_id): array
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $found = array();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $post_ids))), self::SLUG_CHUNK_SIZE) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '%d'));
            $query = "SELECT DISTINCT post_id FROM $table_name_process_post
                WHERE lpagery_process_id != %d AND post_id IN ($in)";
            $found = array_merge($found, $wpdb->get_col($wpdb->prepare($query, array_merge(array($process_id), $chunk))));
        }
        return array_map('intval', $found);
    }

    /**
     * The media-library items whose slug is one of `$slugs`. A page can't take an attachment's slug,
     * so WordPress would number it instead.
     *
     * @param string[] $slugs
     * @return array<int, object> rows of { id, post_name }
     */
    public function find_attachments_by_slugs(array $slugs): array
    {
        global $wpdb;

        $attachments = array();
        foreach (array_chunk(array_values(array_unique($slugs)), self::SLUG_CHUNK_SIZE) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '%s'));
            $query = "SELECT p.ID AS id, p.post_name
                FROM $wpdb->posts p
                WHERE p.post_type = 'attachment'
                    AND p.post_name IN ($in)";
            $attachments = array_merge($attachments, $wpdb->get_results($wpdb->prepare($query, $chunk)) ?: array());
        }
        return $attachments;
    }

    /**
     * Which of `$ids` name a post that can be a parent: published, draft or private, the statuses
     * {@see find_post_by_id()} accepts.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function find_post_ids_by_ids(array $ids): array
    {
        global $wpdb;

        $found = array();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), self::SLUG_CHUNK_SIZE) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '%d'));
            $query = "SELECT p.ID FROM $wpdb->posts p
                WHERE p.ID IN ($in)
                    AND p.post_status IN ('private', 'draft', 'publish')";
            $found = array_merge($found, $wpdb->get_col($wpdb->prepare($query, $chunk)));
        }
        return array_map('intval', $found);
    }

    /**
     * The post of `$post_type` each of `$names` (slugs) names, the oldest first when several share
     * one, as {@see find_post_by_name_and_type_equal()} picks it.
     *
     * @param string[] $names
     * @return array<string, int> lowercased slug => post id
     */
    public function find_post_ids_by_names(array $names, string $post_type): array
    {
        global $wpdb;

        $found = array();
        $names = array_values(array_unique(array_map('strtolower', $names)));
        foreach (array_chunk($names, self::SLUG_CHUNK_SIZE) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '%s'));
            $query = "SELECT p.ID AS id, LOWER(p.post_name) AS post_name FROM $wpdb->posts p
                WHERE p.post_name IN ($in)
                    AND p.post_type = %s
                    AND p.post_status IN ('publish', 'draft', 'private')
                ORDER BY p.post_date, p.ID";
            foreach ($wpdb->get_results($wpdb->prepare($query, array_merge($chunk, array($post_type)))) ?: array() as $post) {
                if (!isset($found[$post->post_name])) {
                    $found[$post->post_name] = (int)$post->id;
                }
            }
        }
        return $found;
    }

    /**
     * The page a `[lpagery_link slug=""]` should point at: an exact slug match first, otherwise the
     * first page whose slug starts with the given one.
     *
     * `publish` only, in both queries. A draft, pending, scheduled or private page has no public
     * permalink — WordPress renders it as `?page_id=N`, which a visitor cannot open — so linking one
     * produced a dead link on a live page. Matching the positional lookup, an unpublished page is
     * skipped rather than linked.
     *
     * @param string $slug
     * @return array<string, mixed>|null row of { id, post_title, post_type }
     */
    public function get_post_by_slug_for_link($slug)
    {
        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare("select p.id , post_title, post_type
                from $wpdb->posts p
                where post_name = %s
                  and post_status = 'publish'", $slug));
        if (empty($results)) {
            $results = $wpdb->get_results($wpdb->prepare("select p.id , post_title, post_type
                from $wpdb->posts p
                where post_name like %s
                  and post_status = 'publish'", $slug . '%'));
        }

        if (empty($results)) {
            return null;
        } else {
            return (array)$results[0];
        }
    }

    /**
     * The Page Set neighbour a `[lpagery_link position=""]` should point at, or null when there is
     * none. Every branch — FIRST, LAST, NEXT, PREV, with and without `circle` — returns the same
     * array shape as {@see get_post_by_slug_for_link()}, so the shortcode has a single code path
     * instead of the FIRST/LAST object vs NEXT/PREV array split that made positional links fatal.
     *
     * `$position` accepts FIRST / LAST / NEXT / PREV, or a numeric offset relative to the current
     * page; anything else resolves to no neighbour.
     *
     * Only `publish` pages are linkable, so the whole set — FIRST, LAST, the NEXT/PREV step and the
     * `circle` wrap-around — is computed over the published pages of the Page Set. A draft or
     * trashed neighbour is skipped rather than linked as a `?page_id=N` a visitor cannot open.
     *
     * @param int|string $post_id the page the shortcode renders on
     * @param int|string $position
     * @param bool $circle wrap around at the ends of the ordered set
     * @return array<string, mixed>|null row of { id, post_title }
     */
    public function get_post_at_position_in_process($post_id, $position, $circle)
    {

        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("select p.id, p.post_title
         from $wpdb->posts p
         inner join $table_name_process_post lpp on lpp.post_id = p.id
         where lpp.lpagery_process_id = (
             select lpagery_process_id
             from $table_name_process_post lpp2
             where lpp2.post_id = %s
         ) and p.post_status = 'publish' order by p.id", $post_id);
        $results = array_values((array)$wpdb->get_results($prepare));
        if (empty($results)) {
            return null;
        }

        $last_index = count($results) - 1;

        if ($position === 'FIRST') {
            return (array)$results[0];
        }
        if ($position === 'LAST') {
            return (array)$results[$last_index];
        }

        if ($position === 'NEXT') {
            $offset = 1;
        } elseif ($position === 'PREV') {
            $offset = -1;
        } elseif (is_numeric($position)) {
            $offset = (int)$position;
        } else {
            return null;
        }

        $target_index = $this->target_index_in_linkable_set($results, $post_id, $offset);

        if ($circle && $target_index > $last_index) {
            return (array)$results[0];
        }
        if ($circle && $target_index < 0) {
            return (array)$results[$last_index];
        }
        if ($target_index >= 0 && $target_index <= $last_index) {
            return (array)$results[$target_index];
        }

        return null;
    }

    /**
     * Where an offset from the current page lands inside the ordered set of linkable pages.
     *
     * The current page is normally one of them. When it is not — only link *targets* are filtered to
     * `publish`, so the page being rendered may itself be a draft an editor is previewing — it is
     * positioned by post id between its published neighbours and the offset counts from there:
     * NEXT reaches the first published page after it, PREV the last published page before it.
     *
     * @param array<int, \stdClass> $linkable published pages, ordered by post id ascending
     * @param int|string $post_id
     */
    private function target_index_in_linkable_set(array $linkable, $post_id, int $offset): int
    {
        foreach ($linkable as $index => $row) {
            if ((int)$row->id === (int)$post_id) {
                return $index + $offset;
            }
        }

        $preceding = 0;
        foreach ($linkable as $row) {
            if ((int)$row->id < (int)$post_id) {
                $preceding++;
            }
        }

        return $offset > 0 ? $preceding + $offset - 1 : $preceding + $offset;
    }

    // -----------------------------------------------------------------------
    // Live Mode cluster (ADR 0008/0010): reads/writes keyed off the per-page
    // `_lpagery_render_mode = 'live'` postmeta marker.
    // -----------------------------------------------------------------------

    /**
     * The per-page Render Mode marker's meta key (ADR 0010). Reads (SQL joins, the meta proxy's key
     * list, `get_post_meta`) still use the literal; the constant names the key for the write path.
     */
    public const RENDER_MODE_META_KEY = '_lpagery_render_mode';

    /** Marker value for a Live Mode Stub Post. */
    public const RENDER_MODE_LIVE = 'live';

    /** Marker value for a classic, self-contained Generated Page. */
    public const RENDER_MODE_CLASSIC = 'classic';

    /**
     * The ONE write path for a Generated Page's Render Mode marker (issue #280). Every place that
     * marks a page live or classic — classic/live generation and update through
     * {@see \LPagery\service\save_page\additional\AdditionalDataSaver}, and the Classic → Live strip
     * in the Strip to Stub service — goes through here, so
     * anything that has to react to a Render Mode change (cache or gate invalidation) has exactly
     * one seam to hook instead of hunting call sites.
     *
     * The write is the historic delete + add pair: the marker is single-valued, and deleting first
     * keeps a page that somehow collected several rows down to one.
     *
     * @throws \InvalidArgumentException if $render_mode is neither `live` nor `classic`; nothing is
     *                                   written in that case.
     */
    public function set_render_mode(int $post_id, string $render_mode): void
    {
        if ($render_mode !== self::RENDER_MODE_LIVE && $render_mode !== self::RENDER_MODE_CLASSIC) {
            throw new \InvalidArgumentException("Unknown render mode: $render_mode");
        }

        delete_post_meta($post_id, self::RENDER_MODE_META_KEY);
        add_post_meta($post_id, self::RENDER_MODE_META_KEY, $render_mode);

        // Creating a page in Live Mode and converting in either direction all land here, so this is
        // the one place the persisted site-wide "any live page exists" answer can go stale (#281).
        // A write that makes a page LIVE bypasses the once-per-request throttle: the token must be
        // bumped AFTER this marker landed, so that a reader which captured the token earlier (and
        // may have queried before the marker existed) can never publish a trusted "no live page".
        // A bump from an earlier write in this request happened before this marker and is not
        // enough. The classic direction can only leave a harmless stale "yes", so it stays throttled.
        if ($render_mode === self::RENDER_MODE_LIVE) {
            $this->live_pages_version_bumped = false;
        }
        $this->invalidate_live_pages_exist();
    }

    /**
     * O(1) render-path lookup: returns the Live Mode stub's Template Page id, Row Data and
     * attachment pairs for a post, or null if it is not a live stub (ADR 0010). Reads directly
     * from the DB — no get_post_meta() — so it is safe to call from inside the get_post_metadata
     * filter without re-entering it. Both join columns (post_id, meta_key) are indexed.
     */
    public function get_live_stub_data($generated_post_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        // The left join carries the Template Page's status along with the row: `null` means the
        // template post row is gone, `trash` means it is in the trash. Either way the page is an
        // Orphaned Live Page and the render pipeline serves it as a 404 (ADR 0017).
        $prepare = $wpdb->prepare("select lpp.template_id as template_id, lpp.data as data, lpp.attachment_id_pairs as attachment_id_pairs, lpp.lpagery_settings as lpagery_settings, lpp.spin_seed as spin_seed, template_post.post_status as template_status
			from $table_name_process_post lpp
			inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
			left join $wpdb->posts template_post on template_post.ID = lpp.template_id
			where lpp.post_id = %d order by lpp.id limit 1", $generated_post_id);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return $results[0];
    }

    /**
     * Site-wide existence check backing the render pipeline's short-circuit: does any Live Mode
     * stub exist at all?
     *
     * The answer is persisted in the autoloaded {@see LIVE_PAGES_EXIST_OPTION} option (issue #281),
     * so a front-end request on a site that never uses Live Mode spends no query on the gate at all
     * — the option rides along with the options WordPress loads anyway. A transient would not,
     * because an expiring transient is stored with `autoload = off` and reading it would cost the
     * very query this saves.
     *
     * A missing option means "not answered yet" and heals itself: the EXISTS query runs once and
     * stores the answer, so a fresh install, a restored backup or a wiped options table needs no
     * migration — the next request writes the answer back.
     *
     * Concurrency: the stored answer is tagged with the {@see LIVE_PAGES_VERSION_OPTION} token read
     * BEFORE the query, and is only trusted while that token is current. A request that queried
     * before a marker turned live and stores its answer afterwards therefore publishes a stale token,
     * and the next reader re-queries. The only staleness left is a stale "yes" when someone deletes
     * the last live page by hand in wp-admin, which costs the per-post lookups today's code already
     * runs until the next LPagery write bumps the token.
     */
    public function live_stubs_exist(): bool
    {
        // The token is captured first: a write that lands between here and the query bumps it, so the
        // answer stored below is already stale by the time it is written and no reader trusts it.
        $version = (string)get_option(self::LIVE_PAGES_VERSION_OPTION, '');
        $stored = get_option(self::LIVE_PAGES_EXIST_OPTION, null);
        if (is_array($stored) && isset($stored['exists'], $stored['version']) && (string)$stored['version'] === $version) {
            return (bool)$stored['exists'];
        }

        $exists = $this->query_live_stubs_exist();

        // `$autoload = true` sticks on the add path (first store on a site); a later store on the
        // existing option keeps it.
        update_option(self::LIVE_PAGES_EXIST_OPTION, array('exists' => $exists, 'version' => $version), true);
        // The option holds a fresh answer again, so the next write must bump the token once more.
        $this->live_pages_version_bumped = false;

        return $exists;
    }

    private function query_live_stubs_exist(): bool
    {
        global $wpdb;

        $exists = $wpdb->get_var("select exists(select 1 from $wpdb->postmeta where meta_key = '_lpagery_render_mode' and meta_value = 'live')");
        return !empty($exists);
    }

    /**
     * Count of Live Mode stubs site-wide, backing the deactivation warning (Phase 9): a live page
     * serves empty content once the render pipeline is gone, so the count is surfaced to the user on
     * plugin deactivation. One cheap COUNT over the render-mode meta.
     */
    public function count_live_stubs(): int
    {
        global $wpdb;

        $count = $wpdb->get_var("select count(1) from $wpdb->postmeta where meta_key = '_lpagery_render_mode' and meta_value = 'live'");
        return (int)$count;
    }

    /**
     * Count of the Live Mode stubs generated from $template_id, across **every** post status, backing
     * the Template Page delete guard (ADR 0017). Unlike {@see get_live_stub_ids_by_template()} this
     * counts trashed stubs too: a trashed live page can be restored, and it would come back needing
     * its template, so a template whose live pages are all in the trash stays guarded.
     */
    public function count_live_stubs_by_template(int $template_id): int
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select count(distinct lpp.post_id)
					from $table_name_process_post lpp
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					where lpp.template_id = %d", $template_id);

        return (int)$wpdb->get_var($prepare);
    }

    /**
     * How many Orphaned Live Pages the site has, split by why they are orphaned, backing the admin
     * warning (ADR 0017 §4). An Orphaned Live Page is a live stub whose template page row is gone
     * (`missing`) or in the trash (`trashed`); either way the page answers visitors with a 404, and the
     * two cases get different copy because Convert to Classic still works while the row exists.
     *
     * Counts only stubs that are not themselves trashed: a trashed page serves nothing either way, so
     * warning about it would be noise. `count(distinct ...)` per branch keeps a page with several
     * process_post rows from counting twice; a page carrying both a trashed and a missing template row
     * (two rows for the same page) shows up in both counts, which is rare enough to accept.
     *
     * @return array{trashed:int,missing:int}
     */
    public function count_orphaned_live_stubs(): array
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $row = $wpdb->get_row("select
					count(distinct case when template_post.ID is null then lpp.post_id end) as missing_count,
					count(distinct case when template_post.post_status = 'trash' then lpp.post_id end) as trashed_count
					from $table_name_process_post lpp
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
					left join $wpdb->posts template_post on template_post.ID = lpp.template_id
					where template_post.ID is null or template_post.post_status = 'trash'");

        if (empty($row)) {
            return array('trashed' => 0, 'missing' => 0);
        }

        return array(
            'trashed' => (int)$row->trashed_count,
            'missing' => (int)$row->missing_count,
        );
    }

    /**
     * The shared FROM/JOIN/WHERE fragment (with a single `%d` process-id placeholder) that both the
     * id-list read and the count of the pages still needing conversion to $target_type build on, so
     * the two deliberately-mirrored WHERE clauses cannot drift apart. `target = live` selects the
     * pages that are not yet live (a LEFT JOIN so absent meta ⇒ classic counts); `target = classic`
     * selects the pages that are still live (an INNER JOIN on the `live` marker). Trashed pages are
     * excluded in both. The exact SQL for each target is preserved.
     */
    private function needing_conversion_from_where(string $target_type): string
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        if ($target_type === 'live') {
            return "from $table_name_process_post lpp
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
					left join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode'
					where lpp.lpagery_process_id = %d and (pm.meta_value is null or pm.meta_value <> 'live')";
        }

        return "from $table_name_process_post lpp
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					where lpp.lpagery_process_id = %d";
    }

    /**
     * The next batch of a Page Set's pages whose Render Mode is not yet $target_type (Phase 7
     * set-level switching). `target = classic` yields the set's still-live pages; `target = live`
     * yields the pages that are classic or unmarked (absent meta ⇒ classic). Capped by $limit so a
     * huge set converts in resumable batches; because the query is a pure function of the pages that
     * still need conversion, an interrupted run just continues on the next call.
     *
     * @return array<int,int>
     */
    public function get_process_post_ids_needing_conversion(int $process_id, string $target_type, int $limit): array
    {
        global $wpdb;

        $prepare = $wpdb->prepare("select lpp.post_id as post_id
					" . $this->needing_conversion_from_where($target_type) . "
					order by lpp.post_id
					limit %d", $process_id, $limit);

        $post_ids = $wpdb->get_col($prepare);
        return array_map('intval', $post_ids);
    }

    /**
     * Every one of a Page Set's pages whose Render Mode is not yet $target_type — the un-limited sibling
     * of {@see get_process_post_ids_needing_conversion()} used to expand a background Render Mode switch
     * into one queue item per page (issue #220 Phase 10). Shares the exact WHERE via
     * {@see needing_conversion_from_where()}, so the background switch selects the same pages the browser
     * batch does; pages already in the target mode are skipped, keeping a re-run idempotent.
     *
     * @return array<int,int>
     */
    public function get_all_process_post_ids_needing_conversion(int $process_id, string $target_type): array
    {
        global $wpdb;

        $prepare = $wpdb->prepare("select lpp.post_id as post_id
					" . $this->needing_conversion_from_where($target_type) . "
					order by lpp.post_id", $process_id);

        $post_ids = $wpdb->get_col($prepare);
        return array_map('intval', $post_ids);
    }

    /**
     * How many of a Page Set's pages still need conversion to $target_type (Phase 7). Drives the
     * `remaining`/`done` fields of the batched switch endpoint. Mirrors the WHERE of
     * {@see get_process_post_ids_needing_conversion()} via the shared
     * {@see needing_conversion_from_where()} builder.
     */
    public function count_process_posts_needing_conversion(int $process_id, string $target_type): int
    {
        global $wpdb;

        $prepare = $wpdb->prepare("select count(lpp.post_id)
					" . $this->needing_conversion_from_where($target_type), $process_id);

        return (int)$wpdb->get_var($prepare);
    }

    /**
     * Total non-trashed pages in a Page Set — the stable denominator for the switch progress bar.
     */
    public function count_process_posts(int $process_id): int
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select count(lpp.post_id)
					from $table_name_process_post lpp
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
					where lpp.lpagery_process_id = %d", $process_id);

        return (int)$wpdb->get_var($prepare);
    }

    /**
     * The live Stub Post ids whose Template Page is $template_id (ADR 0008 propagation). Used on
     * template save to purge exactly the affected pages; capped by $limit so a huge set never
     * materialises a full id list in PHP — the caller reads the cap as "too many, purge site-wide".
     * Live-ness is decided per page via the `_lpagery_render_mode` meta, so a mixed Page Set
     * yields only its live pages.
     *
     * $after_id makes the same lookup pageable by keyset: pass the highest id of the previous page
     * to walk the complete set in chunks (the object-cache invalidation of the full-site purge
     * branch needs every id, not just the capped first page). Still keyed off the
     * process/postmeta tables, never a scan of wp_posts.
     *
     * @return array<int,int>
     */
    public function get_live_stub_ids_by_template(int $template_id, int $limit, int $after_id = 0): array
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.post_id as post_id
					from $table_name_process_post lpp
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
					where lpp.template_id = %d and lpp.post_id > %d
					order by lpp.post_id
					limit %d", $template_id, $after_id, $limit);

        $post_ids = $wpdb->get_col($prepare);
        return array_map('intval', $post_ids);
    }

    /**
     * One live Stub Post that carries a Virtual Image Map, for the **Endpoint Health** probe (ADR 0014):
     * the probe needs a single real Virtual Image URL to fetch over loopback. Returns `{post_id,
     * attachment_id_pairs}` (the serialized pairs column) for the newest such stub, or null when no live
     * stub holds a Virtual Image Map — in which case the probe is a no-op (nothing to fetch, verdict
     * untouched). The `LIKE` matches the serialized {@see \LPagery\service\image_endpoint\VirtualImageMap::KEY}
     * discriminator, so legacy `{source,target}` stubs (which lack it) are skipped without deserializing.
     */
    public function get_live_stub_with_virtual_image()
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.post_id as post_id, lpp.attachment_id_pairs as attachment_id_pairs
					from $table_name_process_post lpp
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
					where lpp.attachment_id_pairs like %s
					order by lpp.id desc limit 1", '%' . $wpdb->esc_like(\LPagery\service\image_endpoint\VirtualImageMap::KEY) . '%');

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return $results[0];
    }

    /**
     * The live Stub Post ids whose **Virtual Image Map** references source attachment $attachment_id — the
     * blast radius of deleting that media (Phase 7 delete guard, ADR 0014). Gated on {@see live_stubs_exist()}
     * so a site with no live pages never scans. The serialized `attachment_id_pairs` column is narrowed with a
     * `LIKE` on the id's serialized-int form (`i:{id};`) — cheap but liable to false-positive on a different
     * numeric field (a `{source,target}` element, a length prefix) — so every candidate is confirmed by
     * deserializing and checking {@see VirtualImageMap::entries()}; a legacy `{source,target}` stub carries no
     * virtual entries and is dropped. Returns distinct ids in scan order; empty when nothing references it.
     *
     * @return array<int,int>
     */
    public function get_live_stub_ids_referencing_attachment(int $attachment_id): array
    {
        if (!$this->live_stubs_exist()) {
            return array();
        }

        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.post_id as post_id, lpp.attachment_id_pairs as attachment_id_pairs
					from $table_name_process_post lpp
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					where lpp.attachment_id_pairs like %s", '%' . $wpdb->esc_like('i:' . $attachment_id . ';') . '%');

        $rows = $wpdb->get_results($prepare);
        if (empty($rows)) {
            return array();
        }

        $referencing = array();
        foreach ($rows as $row) {
            $pairs = maybe_unserialize($row->attachment_id_pairs);
            if (!is_array($pairs)) {
                continue;
            }
            foreach (\LPagery\service\image_endpoint\VirtualImageMap::entries($pairs) as $entry) {
                if ($entry['source_id'] === $attachment_id) {
                    $referencing[(int)$row->post_id] = true;
                    break;
                }
            }
        }

        return array_map('intval', array_keys($referencing));
    }

    /**
     * Every live Stub Post id site-wide, capped by $limit — the purge target when the **Endpoint Health**
     * verdict flips (ADR 0014). Fired on a health transition so cached HTML converges to the new
     * render shape (Virtual Image URLs ⇄ Source URL Fallback) instead of waiting for expiry; capped like
     * the by-template/by-process lookups so a huge site degrades to a full-site purge rather than
     * materialising every id. Zero live stubs yields an empty array — the caller treats it as nothing to
     * purge.
     *
     * @return array<int,int>
     */
    public function get_all_live_stub_ids(int $limit): array
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.post_id as post_id
					from $table_name_process_post lpp
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
					order by lpp.post_id
					limit %d", $limit);

        $post_ids = $wpdb->get_col($prepare);
        return array_map('intval', $post_ids);
    }

    /**
     * The live Stub Post ids belonging to Page Set $process_id (Phase 8 sheet sync). Fired on sync
     * completion to purge the set's live pages; capped by $limit like the by-template lookup so a
     * huge set degrades to a full-site purge rather than materialising every id. A classic set (no
     * live-marked pages) yields an empty array, which the caller treats as "nothing to purge" — so
     * classic sync stays untouched.
     *
     * @return array<int,int>
     */
    public function get_live_stub_ids_by_process(int $process_id, int $limit): array
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.post_id as post_id
					from $table_name_process_post lpp
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
					where lpp.lpagery_process_id = %d
					order by lpp.post_id
					limit %d", $process_id, $limit);

        $post_ids = $wpdb->get_col($prepare);
        return array_map('intval', $post_ids);
    }

    /**
     * Bump `post_modified`/`post_modified_gmt` on every live Stub Post of $template_id in one bulk
     * UPDATE (ADR 0008): the whole "update all pages" for a design change, so sitemap `<lastmod>`
     * reflects the template edit and crawlers recrawl — with zero per-page writes. Keyed off the
     * per-page live marker via a subquery on the process/postmeta tables (never on wp_posts itself),
     * so it scales to the entire Page Set. Returns the number of stubs bumped.
     */
    public function bump_live_stub_modified_by_template(int $template_id, string $modified, string $modified_gmt): int
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $result = $wpdb->query($wpdb->prepare(
            "update $wpdb->posts
					set post_modified = %s, post_modified_gmt = %s
					where post_status != 'trash' and ID in (
						select lpp.post_id
						from $table_name_process_post lpp
						inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
						where lpp.template_id = %d
					)",
            $modified,
            $modified_gmt,
            $template_id
        ));

        if ($result === false) {
            error_log("LPagery live stub modified bump failed for template $template_id: " . $wpdb->last_error);
            return 0;
        }

        return is_numeric($result) ? (int)$result : 0;
    }

    /**
     * Drop Elementor's per-document element cache (`_elementor_element_cache`) from every live Stub
     * Post of $template_id in one bulk DELETE (#287). Elementor clears that meta only for the
     * document it saved — the Template Page — so without this a stub that was rendered before the
     * save keeps serving the old builder markup for the meta's TTL (24 hours by default), and the
     * first such stub to be rendered also poisons the shared render fragment for the whole set.
     *
     * Multi-table DELETE rather than the subquery shape of {@see bump_live_stub_modified_by_template()}:
     * the live marker lives in the very table being deleted from, which MySQL refuses to read in a
     * subquery of the same statement. Keyed off the process/postmeta tables like the bump, so it
     * scales to the whole Page Set with no per-page write. Runs whether or not Elementor is
     * installed — deleting an absent meta row is a no-op. Returns the number of rows removed.
     */
    public function delete_live_stub_elementor_element_cache_by_template(int $template_id): int
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $result = $wpdb->query($wpdb->prepare(
            "delete cache
					from $wpdb->postmeta cache
					inner join $table_name_process_post lpp on lpp.post_id = cache.post_id
					inner join $wpdb->postmeta pm on pm.post_id = lpp.post_id and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'
					where cache.meta_key = '_elementor_element_cache' and lpp.template_id = %d",
            $template_id
        ));

        if ($result === false) {
            error_log("LPagery Elementor element cache purge failed for template $template_id: " . $wpdb->last_error);
            return 0;
        }

        return is_numeric($result) ? (int)$result : 0;
    }

    /**
     * Flip the Page Set's config-blob Render Mode once a set-level switch has converted every
     * page (Phase 7). `live` writes the key; `classic` unsets it, keeping classic config blobs
     * byte-for-byte identical to sets that never used Live Mode (matching the create-flow convention
     * in {@see \LPagery\controller\ProcessController::extractProcessData()}).
     *
     * Reads the config blob directly from `lpagery_process` (rather than depending on the page-set
     * repository or a DAO round-trip): a null blob — an absent row — is a no-op, matching the legacy
     * "no process, no write" behaviour.
     */
    public function update_process_render_mode(int $process_id, string $target_type): void
    {
        global $wpdb;

        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $stored = $wpdb->get_var($wpdb->prepare("SELECT data FROM $table_name_process WHERE id = %d", $process_id));
        if ($stored === null) {
            return;
        }
        $data = maybe_unserialize($stored);
        if (!is_array($data)) {
            $data = array();
        }
        if ($target_type === 'live') {
            $data['render_mode'] = 'live';
        } else {
            unset($data['render_mode']);
        }
        $updated = $wpdb->update($table_name_process, array("data" => serialize($data)), array("id" => $process_id));
        if ($updated === false) {
            throw new \RuntimeException("Could not update the Render Mode of page set $process_id: " . $wpdb->last_error);
        }
    }

    /**
     * Batch-fetch the serialized placeholder `data` blob for a set of Generated Pages in one query.
     * Used by the renderer to fill placeholder-sourced Card slots (e.g. label = "service") without
     * an N+1 deserialize per card. Returns rows of { post_id, data }; pages with no process-post
     * row are simply absent. Order is not guaranteed — the renderer keys results by post_id.
     *
     * @param int[] $post_ids
     * @return array<int, object> rows of { post_id, data }
     */
    public function get_process_post_data_for_post_ids(array $post_ids): array
    {
        if (empty($post_ids)) {
            return array();
        }

        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $sanitized_ids = array_map('intval', $post_ids);
        $placeholders = implode(',', array_fill(0, count($sanitized_ids), '%d'));

        $prepare = $wpdb->prepare(
            "select lpp.post_id as post_id, lpp.data as data, lpp.attachment_id_pairs as attachment_id_pairs
                from $table_name_process_post lpp
                where lpp.post_id in ($placeholders)",
            $sanitized_ids);

        return $wpdb->get_results($prepare);
    }

    // -----------------------------------------------------------------------
    // Overview aggregates (issue #269): the site-wide page counts the Overview
    // tab reports. Every one of them counts DISTINCT wp_posts rows reached
    // through `lpagery_process_post` with trashed posts excluded, so a page in
    // two Page Sets counts once and a discarded page counts not at all.
    // -----------------------------------------------------------------------

    /**
     * The site's Generated Page total split by publish status: `publish` is published, `draft` is
     * draft, and every other status (pending, private, future, any custom one) falls into `other`,
     * so the three parts always add up to `total`.
     *
     * One aggregate query with conditional `count(distinct ...)` branches rather than a grouped
     * read plus PHP bucketing, so the whole split costs a single scan and the caller needs no
     * knowledge of which statuses a site uses.
     *
     * @return array{total:int,published:int,draft:int,other:int}
     */
    public function count_pages_by_status(): array
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $row = $wpdb->get_row("select
					count(distinct p.ID) as total_count,
					count(distinct case when p.post_status = 'publish' then p.ID end) as published_count,
					count(distinct case when p.post_status = 'draft' then p.ID end) as draft_count,
					count(distinct case when p.post_status not in ('publish', 'draft') then p.ID end) as other_count
					from $table_name_process_post lpp
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'");

        // get_row() answers null both for an empty site and for a failed query; only last_error
        // tells them apart, and a failed read must not show the Overview an empty site.
        if ($wpdb->last_error) {
            throw new Exception("Failed to count Generated Pages by status " . $wpdb->last_error);
        }
        if (empty($row)) {
            return array('total' => 0, 'published' => 0, 'draft' => 0, 'other' => 0);
        }

        return array(
            'total' => (int)$row->total_count,
            'published' => (int)$row->published_count,
            'draft' => (int)$row->draft_count,
            'other' => (int)$row->other_count,
        );
    }

    /**
     * The site's Generated Page total split by observed Render Mode. A page is live when it carries
     * the `_lpagery_render_mode = 'live'` marker (ADR 0010) and classic otherwise, so a page written
     * before Live Mode existed counts as classic. `classic + live` always equals the page total.
     *
     * The marker is probed with EXISTS rather than joined, so a page that ended up with a duplicate
     * meta row still counts once.
     *
     * @return array{classic:int,live:int}
     */
    public function count_pages_by_render_mode(): array
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $live_marker = "select 1 from $wpdb->postmeta pm where pm.post_id = p.ID"
            . " and pm.meta_key = '_lpagery_render_mode' and pm.meta_value = 'live'";

        $row = $wpdb->get_row("select
					count(distinct case when exists($live_marker) then p.ID end) as live_count,
					count(distinct case when not exists($live_marker) then p.ID end) as classic_count
					from $table_name_process_post lpp
					inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'");

        if ($wpdb->last_error) {
            throw new Exception("Failed to count Generated Pages by render mode " . $wpdb->last_error);
        }
        if (empty($row)) {
            return array('classic' => 0, 'live' => 0);
        }

        return array(
            'classic' => (int)$row->classic_count,
            'live' => (int)$row->live_count,
        );
    }

    /**
     * How many Generated Pages the site gained in each calendar month, keyed by `YYYY-MM` and
     * ordered oldest first. Only months that actually have pages appear, so the caller fills the
     * gaps; the Overview turns this sparse answer into its twelve buckets.
     *
     * A page that belongs to several Page Sets carries one membership row per set, so the earliest
     * of those rows decides its month and the page is counted once. The `created` column is stamped
     * with `current_time('mysql')`, which is already the site's timezone, so the month is read
     * straight off the stored value with no conversion.
     *
     * @return array<string, int> month (`YYYY-MM`) => page count
     */
    public function count_pages_created_per_month(): array
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $rows = $wpdb->get_results("select
					date_format(first_membership.first_created, '%Y-%m') as created_month,
					count(*) as page_count
					from (
						select lpp.post_id as post_id, min(lpp.created) as first_created
						from $table_name_process_post lpp
						inner join $wpdb->posts p on p.ID = lpp.post_id and p.post_status != 'trash'
						group by lpp.post_id
					) first_membership
					group by created_month
					order by created_month asc");

        if ($wpdb->last_error) {
            throw new Exception("Failed to count Generated Pages per month " . $wpdb->last_error);
        }

        $counts = array();
        foreach ((array)$rows as $row) {
            $counts[(string)$row->created_month] = (int)$row->page_count;
        }

        return $counts;
    }
}
