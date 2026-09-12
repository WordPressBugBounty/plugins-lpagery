<?php

namespace LPagery\service\save_page;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Records that an LPagery-generated page was edited by hand — stamping `page_manually_updated_at/by`
 * on its `lpagery_process_post` row so later data updates/syncs can respect the Manual Change.
 *
 * This is the testable seam behind the `save_post` hook in lpagery.php. The stamp is keyed on the
 * post id and does not look at Render Mode, so it covers Live Mode stubs too (ADR 0010): a
 * quick-edit of a stub's physical scalars — title, slug, status — is a Manual Change just like an
 * edit to a classic page. The proxy is inactive in wp-admin, so the scalars saved are the stub's own.
 */
class ManualChangeTracker
{
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(GeneratedPageRepository $generatedPageRepository)
    {
        $this->generatedPageRepository = $generatedPageRepository;
    }


    /**
     * Stamp the Manual Change unless this save is one we never treat as a hand edit: an unauthenticated
     * user, a programmatic save (cron or LPagery's own generation — decided from constants by the hook
     * and passed in as $is_programmatic so this stays free of WP-global state), a non-update (fresh
     * insert), a metadata save with no submitted form ($_POST empty), or an internal write from the WP
     * Internal Links plugin (`wpil_*` actions). Returns whether the stamp was written.
     */
    public function maybe_stamp(int $post_id, bool $is_update, ?string $post_action, int $current_user_id, bool $has_post_data, bool $is_programmatic): bool
    {
        if ($current_user_id <= 0) {
            return false;
        }
        if ($is_programmatic) {
            return false;
        }
        if (!$has_post_data) {
            return false;
        }
        if ($post_action !== null && str_starts_with($post_action, 'wpil_')) {
            return false;
        }
        if (!$is_update) {
            return false;
        }

        $this->generatedPageRepository->stamp_manual_change($post_id, $current_user_id);
        return true;
    }
}
