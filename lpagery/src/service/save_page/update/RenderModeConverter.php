<?php

namespace LPagery\service\save_page\update;

/**
 * Converts one Generated Page to one Render Mode (ADR 0009/0010/0019).
 *
 * The seam the conversion services share, so a caller can drive either direction through one type:
 * the free {@see MaterializeService} answers `classic` and the Extended-tier Strip to Stub service
 * answers `live`, each refusing the direction that is not theirs. The background worker loopback in
 * {@see \LPagery\controller\CreatePostController} and {@see RenderModeBatchSwitcher} both hold
 * converters by this interface.
 */
interface RenderModeConverter
{
    /**
     * @param string $direction `classic` to materialize the page, `live` to strip it back to a stub
     */
    public function convert_page(int $post_id, string $direction): void;
}
