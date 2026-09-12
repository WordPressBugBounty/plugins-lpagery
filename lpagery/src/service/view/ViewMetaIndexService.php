<?php

namespace LPagery\service\view;

use LPagery\data\repository\PageMetaIndexRepository;

/**
 * Policy for the sparse meta index (wp_lpagery_process_post_meta) that backs related-pages Views.
 *
 * This service holds no SQL and no write plumbing: it answers the two index-policy questions the
 * resolver and backfill worker ask — is a key Indexed for a Process (some View matches on it,
 * ADR-0001), and is that key ready to be served from the index. Index maintenance itself (the
 * per-page upserts and the trash/delete cleanup) lives on PageMetaIndexRepository +
 * GeneratedPageRepository; keeping the writes there and the policy here breaks the old
 * DAO↔service cycle and leaves a single, one-way index-maintenance path.
 *
 * Per-key readiness: a key becomes "ready" (served from the index) only after its backfill
 * completes (#166). Until then the resolver falls back to live deserialization, which keeps
 * #163 behavior-invariant (identical results either way).
 *
 * Index policy is intentionally license-agnostic: it keeps answering on downgraded sites so
 * already-Indexed keys stay fast (ADR-0002 downgrade safety).
 */
class ViewMetaIndexService
{
    private PageMetaIndexRepository $pageMetaIndexRepository;

    public function __construct(PageMetaIndexRepository $pageMetaIndexRepository)
    {
        $this->pageMetaIndexRepository = $pageMetaIndexRepository;
    }

    /**
     * Whether a key is indexed for the Process (used as a match key by at least one View).
     */
    public function is_indexed_key(int $process_id, string $key): bool
    {
        return in_array($key, $this->pageMetaIndexRepository->get_indexed_keys_for_process($process_id), true);
    }

    /**
     * Whether the index for (process, key) is ready to serve resolver reads. False until the
     * key's backfill completes (#166), which is what keeps #163 behavior-invariant: an indexed
     * but not-yet-backfilled key is served from the live fallback, not a partial index.
     */
    public function is_key_ready(int $process_id, string $key): bool
    {
        return (bool)get_option($this->readiness_option_name($process_id, $key), false);
    }

    /**
     * Mark (process, key) ready / not-ready. Backfill (#166) flips this to true on completion;
     * removing the last View that uses a key can flip it back / clear it.
     */
    public function set_key_ready(int $process_id, string $key, bool $ready): void
    {
        $option = $this->readiness_option_name($process_id, $key);
        if ($ready) {
            update_option($option, true, false);
        } else {
            delete_option($option);
        }
    }

    private function readiness_option_name(int $process_id, string $key): string
    {
        // md5 the key so arbitrary placeholder names stay within option_name length limits.
        return "lpagery_view_index_ready_" . $process_id . "_" . md5($key);
    }
}
