<?php

namespace LPagery\service\preparation;

use LPagery\model\BaseParams;

/**
 * Resolves the image columns of a row into attachment IDs and substitution values.
 *
 * Image processing is Extended-tier and its implementation is stripped from the free build, so
 * {@see InputParamProvider} — free code on the creation path — types the collaborator by this
 * interface and gets `null` on the free tier (ADR 0011).
 */
interface MediaParamProvider
{
    /**
     * Five positional values, consumed with `list()`: image keys, image values, source attachment
     * IDs, target attachment IDs and the Virtual Image Map. Empty when the row has no image column.
     *
     * @return array<int, mixed>
     */
    public function provideMediaParams(BaseParams $params, $source_post_id, string $render_mode = 'classic'): array;
}
