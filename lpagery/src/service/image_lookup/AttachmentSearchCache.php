<?php

namespace LPagery\service\image_lookup;

/**
 * The per-run cache in front of the attachment search, seen from free code.
 *
 * {@see \LPagery\controller\CreatePostController} drops the cache at the start of every run. The
 * caching search service itself is Extended-tier and stripped from the free build, so the controller
 * names only this interface and receives `null` on the free tier (ADR 0011).
 */
interface AttachmentSearchCache
{
    public function evict_cache(): void;
}
