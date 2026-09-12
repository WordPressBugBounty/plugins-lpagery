<?php

namespace LPagery\service\live_render;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Propagates a Template Page edit to every live page of the Page Sets built from it (ADR 0008),
 * with zero per-page content writes:
 *
 *   1. drop the template's render fragment so the next request re-renders the new design;
 *   2. bump `post_modified` on all affected live stubs in one bulk UPDATE (sitemap `<lastmod>`);
 *   3. drop Elementor's `_elementor_element_cache` meta from those stubs in one bulk DELETE, since
 *      Elementor clears it only for the document it saved — the template — and a stub that kept it
 *      would serve the old builder markup and poison the shared fragment (#287);
 *   4. purge the cache plugins for those pages, and invalidate the core `posts` object cache the
 *      bulk UPDATE bypassed.
 *
 * Hung on `save_post` (and Elementor's editor save), so it fires for every post save on the site: the
 * affected-stub lookup is a single indexed query that returns nothing for a normal post, and the
 * result is memoised per request so double-firing hooks (e.g. save_post + elementor/editor/after_save
 * for the same edit) do the work once. Saving a Stub Post is itself a cheap no-op — its own id is not
 * a template of any live set.
 */
class LiveTemplateSaveHandler
{
    private GeneratedPageRepository $generatedPageRepository;
    private LiveFragmentCache $fragmentCache;
    private LiveCachePurger $purger;
    /** @var array<int,true> post ids already propagated this request. */
    private array $handled = array();

    public function __construct(GeneratedPageRepository $generatedPageRepository, LiveFragmentCache $fragmentCache, LiveCachePurger $purger)
    {
        $this->generatedPageRepository = $generatedPageRepository;
        $this->fragmentCache = $fragmentCache;
        $this->purger = $purger;
    }


    /**
     * Restoring a Template Page from the trash (ADR 0017): drop its render fragment and the stubs'
     * Elementor element caches, then purge the live pages that render from it, so the pages stop
     * being served from what was cached while the template sat in the trash. No `post_modified` bump, unlike a template save: nothing about the
     * pages themselves changed, so sitemap `<lastmod>` must not move. A template only reaches the
     * trash out of band (the guard refuses the interactive route), so this is the recovery path.
     *
     * Also hung on every `untrashed_post`, so a restored live stub clears its own Elementor element
     * cache — the template-keyed bulk clear cannot reach a stub that was in the trash when the
     * template was saved.
     */
    public function handle_template_restore(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        // The restored post may itself be a live stub. The bulk element-cache clear of a template
        // save only reaches a template that still has untrashed live stubs, so a stub that sat in the
        // trash through such a save would come back with its pre-save Elementor markup. Its own
        // `_elementor_element_cache` goes here instead: one meta read, one delete, nothing for a
        // classic page (#287).
        if (get_post_meta($post_id, GeneratedPageRepository::RENDER_MODE_META_KEY, true) === GeneratedPageRepository::RENDER_MODE_LIVE) {
            delete_post_meta($post_id, '_elementor_element_cache');
        }

        // Same capped fetch as the save path: past the threshold the purger degrades to one
        // full-site purge per cache plugin, so the complete id list is never needed here.
        $affected_ids = $this->generatedPageRepository->get_live_stub_ids_by_template(
            $post_id,
            LiveCachePurger::FULL_PURGE_THRESHOLD + 1
        );
        if (empty($affected_ids)) {
            return;
        }

        $this->fragmentCache->delete($post_id);
        // The design may have moved on while the template sat in the trash, so the stubs' Elementor
        // element caches go with the fragment (#287).
        $this->generatedPageRepository->delete_live_stub_elementor_element_cache_by_template($post_id);
        $this->purger->purge_posts($affected_ids);
    }

    public function handle_template_save(int $post_id): void
    {
        if ($post_id <= 0 || isset($this->handled[$post_id])) {
            return;
        }

        // One indexed query: the ids of the live stubs this template feeds, capped one past the
        // full-purge threshold so a huge set is recognised without materialising every id in PHP.
        $affected_ids = $this->generatedPageRepository->get_live_stub_ids_by_template(
            $post_id,
            LiveCachePurger::FULL_PURGE_THRESHOLD + 1
        );
        if (empty($affected_ids)) {
            // Not a Template Page of any live set (the common case for a normal post save).
            return;
        }
        $this->handled[$post_id] = true;

        $this->fragmentCache->delete($post_id);
        $this->generatedPageRepository->bump_live_stub_modified_by_template(
            $post_id,
            current_time('mysql'),
            current_time('mysql', true)
        );
        // Elementor keeps the built markup of each document in its own `_elementor_element_cache`
        // meta and clears only the document that was saved — the template — so without this the
        // stubs serve the old design until that meta expires (#287).
        $this->generatedPageRepository->delete_live_stub_elementor_element_cache_by_template($post_id);
        // The bump wrote wp_posts directly, so the purge also has to drop the core `posts` object
        // cache; above the threshold that needs the complete id set, which $affected_ids is capped
        // short of — hence the keyset pager.
        $this->purger->purge_posts_after_bulk_bump(
            $affected_ids,
            function (int $after_id, int $limit) use ($post_id): array {
                return $this->generatedPageRepository->get_live_stub_ids_by_template($post_id, $limit, $after_id);
            }
        );
    }
}
