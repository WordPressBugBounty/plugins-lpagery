<?php

namespace LPagery\service\image_endpoint;

/**
 * The Image Endpoint's decision logic as a pure function (ADR 0013): given a stub's Virtual Image Map
 * entries and a requested filename, decide whether to serve (and which source attachment) or 404.
 * Deliberately free of HTTP and WordPress so the closed URL space can be tested exhaustively; the HTTP
 * shell ({@see ImageEndpoint}) stays thin.
 *
 * Matching is two-pass: an exact full-size filename match first, then — only if that misses — a `-WxH`
 * size-suffix match ({@see VirtualImageUrl::parse_size()}) whose base filename matches an entry and whose
 * dimensions match one of the source attachment's registered sizes. Exact-first means a substituted
 * filename that itself looks sized (`photo-300x200.jpg`) is served as its own full-size image, never
 * reinterpreted as a size request.
 *
 * Inputs model the closed URL space end to end: the stub's persisted `attachment_id_pairs` (or `null`
 * when no live stub resolves), the requested filename, a lazy on-disk check for the matched source file,
 * and a lazy lookup of the matched attachment's registered sizes. Every miss returns a typed 404 reason
 * (issue #222 Phase 2) so each 404 class is testable.
 */
class VirtualImageResolver
{
    /**
     * @param array<string,mixed>|null $attachment_pairs the stub's persisted pairs column, or null when
     *        no live stub resolves for the post (missing post or non-live post — both a genuine 404).
     * @param callable(int):bool $source_file_exists lazy check, invoked only for the matched entry, that
     *        reports whether the source attachment's file is present on disk.
     * @param (callable(int):array<int,array{width:int,height:int,file:string}>)|null $source_registered_sizes
     *        lazy lookup of the matched attachment's registered size variants (from metadata), invoked
     *        only for a `-WxH` request. Null (or omitted) means "no sizes known" — full-size only.
     */
    public function resolve(?array $attachment_pairs, string $requested_filename, callable $source_file_exists, ?callable $source_registered_sizes = null): VirtualImageDecision
    {
        if ($attachment_pairs === null) {
            return VirtualImageDecision::not_found(VirtualImageDecision::REASON_STUB_NOT_FOUND);
        }

        $entries = VirtualImageMap::entries($attachment_pairs);
        if (empty($entries)) {
            // A live stub with no Virtual Image Map — the legacy {source,target} shape (or an empty map)
            // owns no virtual filenames, so it can never serve one.
            return VirtualImageDecision::not_found(VirtualImageDecision::REASON_LEGACY_STUB);
        }

        $requested_filename = ltrim($requested_filename, '/');
        foreach ($entries as $entry) {
            if ($entry['filename'] === $requested_filename) {
                if (!$source_file_exists($entry['source_id'])) {
                    return VirtualImageDecision::not_found(VirtualImageDecision::REASON_SOURCE_FILE_MISSING);
                }
                return VirtualImageDecision::serve($entry['source_id'], $entry['filename']);
            }
        }

        // No exact full-size match — try a `-WxH` size variant of one of the entries.
        $size = VirtualImageUrl::parse_size($requested_filename);
        if ($size !== null) {
            foreach ($entries as $entry) {
                if ($entry['filename'] !== $size['base']) {
                    continue;
                }
                $registered = $source_registered_sizes !== null ? $source_registered_sizes($entry['source_id']) : array();
                foreach ($registered as $variant) {
                    if ((int)$variant['width'] === $size['width'] && (int)$variant['height'] === $size['height']
                        && isset($variant['file']) && $variant['file'] !== '') {
                        return VirtualImageDecision::serve_size($entry['source_id'], $requested_filename, (string)$variant['file']);
                    }
                }
                // The base matched a live entry but no registered size has these dimensions.
                return VirtualImageDecision::not_found(VirtualImageDecision::REASON_SIZE_NOT_REGISTERED);
            }
        }
        return VirtualImageDecision::not_found(VirtualImageDecision::REASON_NO_MATCH);
    }
}
