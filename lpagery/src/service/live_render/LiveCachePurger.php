<?php

namespace LPagery\service\live_render;

/**
 * Active purge of third-party full-page caches for a set of live pages (ADR 0008). Live Mode
 * substitutes at render time, so a template edit or data sync leaves every cache plugin serving
 * stale HTML until natural expiry; this orchestrator tells them to drop the affected URLs.
 *
 * Each integration is gated on the plugin's own function/constant (via {@see CachePluginDetector})
 * so an absent plugin is skipped, and core `clean_post_cache` always runs. Above
 * {@see self::FULL_PURGE_THRESHOLD} pages a single full-site purge per plugin replaces tens of
 * thousands of per-post calls.
 *
 * The full-site branch also invalidates the core `posts` object cache — a group flush, or a chunked
 * delete over the complete affected id set where the drop-in cannot flush a group — because callers
 * that rewrote `wp_posts` by raw SQL ({@see self::purge_posts_after_bulk_bump()}) would otherwise
 * leave a persistent object cache serving the pre-update row.
 *
 * Fired on template save (this phase) and, from Phase 8, on data sync completion — hence the public
 * {@see self::purge_posts()} seam that both callers share.
 */
class LiveCachePurger
{
    /** Above this many pages, one full-site purge per plugin beats N single-post purges. */
    public const FULL_PURGE_THRESHOLD = 500;

    /** Ids per `wp_cache_delete_multiple` call when the drop-in cannot flush a whole group. */
    public const OBJECT_CACHE_CHUNK = 500;

    private CachePluginDetector $detector;

    public function __construct(CachePluginDetector $detector)
    {
        $this->detector = $detector;
    }


    /**
     * Purge every installed cache plugin for the given live page IDs. Above the threshold this
     * degrades to one full-site purge per plugin instead of iterating the list.
     *
     * @param array<int,int> $post_ids
     */
    public function purge_posts(array $post_ids): void
    {
        $post_ids = array_values(array_unique(array_map('intval', $post_ids)));
        if (empty($post_ids)) {
            return;
        }
        if (count($post_ids) > self::FULL_PURGE_THRESHOLD) {
            $this->purge_all();
            return;
        }
        foreach ($post_ids as $post_id) {
            $this->purge_post($post_id);
        }
    }

    /**
     * One full-site purge per installed cache plugin — the escape hatch when the affected set is too
     * large to purge page by page.
     */
    public function purge_all(): void
    {
        if ($this->detector->function_available('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if ($this->detector->function_available('litespeed_purge_all')) {
            litespeed_purge_all();
        } elseif ($this->detector->constant_defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }
        if ($this->detector->function_available('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if ($this->detector->function_available('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
    }

    /**
     * Purge for a set whose `wp_posts` rows were just rewritten by direct SQL (the bulk
     * `post_modified` bump on template save): the usual purges plus invalidation of the core `posts`
     * object-cache group, which a raw UPDATE bypasses. Without it a persistent object cache (Redis,
     * Memcached) keeps serving the pre-bump row until natural expiry, so sitemap `<lastmod>` lags.
     *
     * @param array<int,int> $post_ids
     */
    public function purge_posts_after_bulk_bump(array $post_ids, callable $next_id_page): void
    {
        $this->purge_posts($post_ids);
        if (count($post_ids) > self::FULL_PURGE_THRESHOLD) {
            $this->invalidate_post_cache_group($next_id_page);
        }
    }

    /**
     * Drop the `posts` object-cache group for the bumped rows: one group flush where the drop-in
     * supports it, otherwise a chunked delete over the complete affected id set — which has to come
     * from $next_id_page, since the caller's array is truncated at the threshold.
     *
     * @param callable(int,int):array<int,int> $next_id_page keyset pager: (after_id, limit) => ids
     */
    private function invalidate_post_cache_group(callable $next_id_page): void
    {
        if ($this->detector->cache_supports('flush_group')) {
            wp_cache_flush_group('posts');
        } else {
            $after_id = 0;
            while (true) {
                $chunk = $next_id_page($after_id, self::OBJECT_CACHE_CHUNK);
                if (empty($chunk)) {
                    break;
                }
                wp_cache_delete_multiple($chunk, 'posts');
                $after_id = (int)max($chunk);
                if (count($chunk) < self::OBJECT_CACHE_CHUNK) {
                    break;
                }
            }
        }
        // Query-result caches (WP_Query, get_pages) key off last_changed, as clean_post_cache does.
        wp_cache_set('last_changed', microtime(), 'posts');
    }

    private function purge_post(int $post_id): void
    {
        // Core object/post cache always: bounds staleness for caches we do not integrate explicitly.
        clean_post_cache($post_id);

        if ($this->detector->function_available('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }
        if ($this->detector->constant_defined('LSCWP_V')) {
            do_action('litespeed_purge_post', $post_id);
        }
        if ($this->detector->function_available('w3tc_flush_post')) {
            w3tc_flush_post($post_id);
        }
        if ($this->detector->function_available('wpsc_delete_post_cache')) {
            wpsc_delete_post_cache($post_id);
        } elseif ($this->detector->function_available('wp_cache_post_change')) {
            wp_cache_post_change($post_id);
        }
    }
}
