<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use LPagery\model\Params;
use WP_Post;

/**
 * Seam for classic-mode pagebuilder handling.
 *
 * Each adapter detects whether it applies to a given Template Page (from the source
 * post and/or the substitution params) and, if so, copies/transforms the builder
 * content onto the Generated Page. The ordered list of adapters is owned by
 * {@see \LPagery\service\save_page\additional\PagebuilderHandler}, which fires every
 * adapter whose supports() returns true (multi-fire).
 */
interface PagebuilderAdapter
{
    /**
     * @param WP_Post $template_post The source Template Page post.
     * @param Params $params The substitution parameters for this generation.
     * @return bool True if this adapter should run for the given template.
     */
    public function supports(WP_Post $template_post, Params $params): bool;

    /**
     * @param int $template_post_id The source Template Page post id.
     * @param int $generated_page_id The target Generated Page post id.
     * @param Params $params The substitution parameters for this generation.
     * @return void
     */
    public function apply(int $template_post_id, int $generated_page_id, Params $params): void;
}
