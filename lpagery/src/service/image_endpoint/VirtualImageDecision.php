<?php

namespace LPagery\service\image_endpoint;

/**
 * The outcome of resolving a Virtual Image URL: either "serve this source attachment under this
 * filename" or a typed 404 reason (ADR 0013 — the URL space is closed, everything else is a genuine
 * 404). Every closed-URL-space miss maps to exactly one typed reason (issue #222 Phase 2).
 *
 * A served decision can name a size variant: {@see self::size_file()} is the source attachment's
 * registered size-variant file basename to stream instead of the full-size file (empty for full-size).
 */
class VirtualImageDecision
{
    /** The stub exists and is live, but no Virtual Image Map entry matches the requested filename. */
    public const REASON_NO_MATCH = 'no_match';
    /** No live stub resolves for the requested post id (the post is missing or is not a Live stub). */
    public const REASON_STUB_NOT_FOUND = 'stub_not_found';
    /** A live stub, but persisted with the legacy {source,target} shape — it owns no virtual filenames. */
    public const REASON_LEGACY_STUB = 'legacy_stub';
    /** Exact filename match, but the source attachment's file is gone from disk. */
    public const REASON_SOURCE_FILE_MISSING = 'source_file_missing';
    /** The base filename matches, but the requested `-WxH` size is not registered for the attachment. */
    public const REASON_SIZE_NOT_REGISTERED = 'size_not_registered';

    private int $source_attachment_id;
    private string $filename;
    private string $size_file;
    private string $reason;

    private function __construct(int $source_attachment_id, string $filename, string $size_file, string $reason)
    {
        $this->source_attachment_id = $source_attachment_id;
        $this->filename = $filename;
        $this->size_file = $size_file;
        $this->reason = $reason;
    }

    public static function serve(int $source_attachment_id, string $filename): self
    {
        return new self($source_attachment_id, $filename, '', '');
    }

    /**
     * Serve a registered size variant: stream `$size_file` (a basename in the source attachment's upload
     * directory) instead of the full-size file.
     */
    public static function serve_size(int $source_attachment_id, string $filename, string $size_file): self
    {
        return new self($source_attachment_id, $filename, $size_file, '');
    }

    public static function not_found(string $reason): self
    {
        return new self(0, '', '', $reason);
    }

    public function is_served(): bool
    {
        return $this->reason === '' && $this->source_attachment_id > 0;
    }

    public function source_attachment_id(): int
    {
        return $this->source_attachment_id;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    /** Registered size-variant file basename to stream, or '' to stream the full-size file. */
    public function size_file(): string
    {
        return $this->size_file;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
