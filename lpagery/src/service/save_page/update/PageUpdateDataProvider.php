<?php

namespace LPagery\service\save_page\update;

use LPagery\model\Params;
use WP_Post;

/**
 * What the free creation graph needs from the Extended-tier page-update handler.
 *
 * {@see CreatePostDelegate} holds the collaborator by this interface, never by the implementing
 * class: the implementation lives in a `__premium_only` file and does not exist on disk in the free
 * build, so a free signature naming it would not resolve there. Free receives `null` (ADR 0011).
 */
interface PageUpdateDataProvider
{
    /**
     * The Generated Page an incoming row should update, or the result that ends the run early.
     */
    public function get_post_to_be_updated(int $process_id, Params $params, WP_Post $template_post, ?int $page_id_to_be_updated, ?int $parent): PageToBeUpdatedResult;

    /**
     * The slug the row would update, for reporting a skipped or rejected row.
     */
    public function getSlugToBeUpdated($element, $process_id): ?string;
}
