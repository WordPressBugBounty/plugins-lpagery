<?php

namespace LPagery\service\image_endpoint;

/**
 * The HTTP caching contract for the Image Endpoint (ADR 0013, issue #222 Phase 2), as pure functions so
 * the validator derivation and the 200-vs-304 decision can be tested without HTTP. The {@see ImageEndpoint}
 * shell only emits these header sets and short-circuits to 304 when {@see self::is_not_modified()} says so.
 *
 * Deliberately never `immutable`: the ETag/Last-Modified are derived from the current source file, so
 * replacing the source attachment's file serves new bytes and new validators at the unchanged URL within
 * the cache window. Revalidation, not permanence.
 */
class ConditionalRequest
{
    /** Browser/CDN cache window in seconds (one day) — public because the served bytes are not per-user. */
    public const MAX_AGE = 86400;

    /**
     * Stale-serving window in seconds (one day): a CDN may keep serving the cached copy for this long past
     * `max-age` while it revalidates in the background, so a source swap never stalls a visitor's request.
     */
    public const STALE_WHILE_REVALIDATE = 86400;

    /** Closed-space 404 cache window in seconds (five minutes) — see {@see self::not_found_headers()}. */
    public const NOT_FOUND_MAX_AGE = 300;

    /**
     * Filter over the full 200/304 `Cache-Control` header value: a support escape hatch to tune the cache
     * TTLs per site without a plugin release. Return value must stay a bare header value (no `immutable`).
     */
    public const CACHE_CONTROL_FILTER = 'lpagery_virtual_image_cache_control';

    /**
     * A strong ETag derived from the source file's size and modification time: changing either (a source
     * swap) changes the validator. Quoted per RFC 7232, no weak (`W/`) prefix.
     */
    public static function etag(int $size, int $mtime): string
    {
        return '"' . dechex($mtime) . '-' . dechex($size) . '"';
    }

    public static function http_date(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }

    /**
     * The full 200/304 `Cache-Control` value: `max-age` (revalidation, never `immutable`) plus a
     * `stale-while-revalidate` window so CDNs serve a stale copy while refreshing. Passed through the
     * {@see self::CACHE_CONTROL_FILTER} escape hatch so the emitted value is whatever support decides.
     */
    public static function cache_control(): string
    {
        $value = 'public, max-age=' . self::MAX_AGE . ', stale-while-revalidate=' . self::STALE_WHILE_REVALIDATE;
        return (string)apply_filters(self::CACHE_CONTROL_FILTER, $value);
    }

    /**
     * The `max-age` (seconds) of the 200/304 response as actually emitted — parsed from the filtered
     * {@see self::cache_control()} value so a site that tunes the TTL through the escape hatch gets the
     * same TTL at the server-cache layer (`X-Accel-Expires`, LiteSpeed) instead of the default. Falls
     * back to {@see self::MAX_AGE} when the filtered value carries no parseable `max-age`.
     */
    public static function max_age(): int
    {
        if (preg_match('/(?:^|[\s,])max-age=(\d+)/i', self::cache_control(), $m) === 1) {
            return (int)$m[1];
        }
        return self::MAX_AGE;
    }

    /**
     * The 200 header set: content headers plus the full revalidation contract.
     *
     * @return array<string,string>
     */
    public static function response_headers(string $mime, int $length, string $etag, int $last_modified): array
    {
        return array(
            'Content-Type' => $mime,
            'Content-Length' => (string)$length,
            'Cache-Control' => self::cache_control(),
            'Last-Modified' => self::http_date($last_modified),
            'ETag' => $etag,
        );
    }

    /**
     * The 304 header set: validators only, no entity/content headers (there is no body).
     *
     * @return array<string,string>
     */
    public static function not_modified_headers(string $etag, int $last_modified): array
    {
        return array(
            'Cache-Control' => self::cache_control(),
            'Last-Modified' => self::http_date($last_modified),
            'ETag' => $etag,
        );
    }

    /**
     * The closed-space 404 header set (ADR 0014): cacheable for five minutes instead of no-cache, so stale
     * Virtual Image references and bot fuzzing stop costing a full WordPress boot each — a fixed source
     * still becomes visible within the window. Never `immutable`.
     *
     * @return array<string,string>
     */
    public static function not_found_headers(): array
    {
        return array(
            'Cache-Control' => 'public, max-age=' . self::NOT_FOUND_MAX_AGE,
        );
    }

    /**
     * Whether the client's cached copy is still fresh (→ 304). Per RFC 7232 §6, when `If-None-Match` is
     * present it is authoritative and `If-Modified-Since` is ignored.
     */
    public static function is_not_modified(string $etag, int $last_modified, ?string $if_none_match, ?string $if_modified_since): bool
    {
        if ($if_none_match !== null && trim($if_none_match) !== '') {
            return self::etag_matches($etag, $if_none_match);
        }
        if ($if_modified_since !== null && trim($if_modified_since) !== '') {
            $since = strtotime($if_modified_since);
            return $since !== false && $last_modified <= $since;
        }
        return false;
    }

    private static function etag_matches(string $etag, string $if_none_match): bool
    {
        $target = self::strip_weak($etag);
        foreach (explode(',', $if_none_match) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (self::strip_weak($candidate) === $target) {
                return true;
            }
        }
        return false;
    }

    private static function strip_weak(string $etag): string
    {
        if (strpos($etag, 'W/') === 0) {
            return substr($etag, 2);
        }
        return $etag;
    }
}
