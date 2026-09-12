<?php

namespace LPagery\service\save_page\update;

use LPagery\model\Params;
use WP_Post;

/**
 * Whether an existing Generated Page (and its content) may be rewritten on an update run.
 *
 * Free-side type for the Extended-tier checker: {@see \LPagery\service\save_page\PageSaver} is free
 * code and cannot name the implementing class, which is stripped from the free build. Free receives
 * `null` and never updates an existing page (ADR 0011).
 */
interface PageUpdateChecker
{
    public function should_page_be_updated(WP_Post $source_post, WP_Post $target_post, Params $params): bool;

    public function should_content_be_updated(WP_Post $source_post, WP_Post $target_post, Params $params): bool;
}
