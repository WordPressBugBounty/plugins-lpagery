<?php

namespace LPagery\service\taxonomies;

use LPagery\model\Params;

/**
 * Assigns the taxonomy terms of a row to a saved Generated Page.
 *
 * Taxonomy output is Standard-tier and its implementation is stripped from the free build, so
 * {@see \LPagery\service\save_page\additional\AdditionalDataSaver} — free code — holds it by this
 * interface and receives `null` on the free tier (ADR 0011).
 */
interface TaxonomyAssigner
{
    /**
     * @return array<int, int>
     */
    public function lpagery_set_taxonomies(Params $params, $post_id): array;
}
