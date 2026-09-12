<?php

namespace LPagery\service\image_endpoint;

/**
 * The Virtual Image URL contract (ADR 0013): a single source of truth for the `/lpagery-img/` route,
 * shared by the render pass (which emits the URLs) and the Image Endpoint (which parses and serves
 * them). Keeping the route, query vars and rewrite regex here means the two sides can never drift.
 *
 * URL form: `/lpagery-img/{stub-post-id}/{substituted-filename}`, with an optional `-WxH` size suffix
 * on the filename (e.g. `hero-berlin-300x200.jpg`) mapping to a registered size variant of the source
 * attachment. {@see self::sized_filename()} emits the suffix (render pass) and {@see self::parse_size()}
 * reverses it (endpoint); the two share this class so the contract can never drift.
 */
class VirtualImageUrl
{
    public const ROUTE = 'lpagery-img';
    public const QUERY_VAR_POST = 'lpagery_img_post';
    public const QUERY_VAR_FILE = 'lpagery_img_file';

    /**
     * The reserved stub id for the Edge Cache Probe (issue #233): 0 is never a real post id, so the probe
     * URL rides the existing rewrite regex without a new rule while staying disjoint from every real
     * Virtual Image URL. The {@see ImageEndpoint} recognises this id and gates the probe image on the
     * token, before its normal live-stubs/resolver path.
     */
    public const PROBE_STUB_ID = 0;

    /** Extension the probe filename carries so an Edge Cache treats the URL as an image. */
    private const PROBE_EXTENSION = '.png';

    public static function path(int $post_id, string $filename): string
    {
        return '/' . self::ROUTE . '/' . $post_id . '/' . ltrim($filename, '/');
    }

    public static function full_url(int $post_id, string $filename): string
    {
        return home_url(self::path($post_id, $filename));
    }

    /**
     * Insert a `-WxH` size suffix before the filename's extension, e.g.
     * `hero-berlin.jpg` + (300, 200) → `hero-berlin-300x200.jpg`. An extension-less filename gets the
     * suffix appended. This is the format the render pass emits for each registered source size variant.
     */
    public static function sized_filename(string $filename, int $width, int $height): string
    {
        $suffix = '-' . $width . 'x' . $height;
        $dot = strrpos($filename, '.');
        if ($dot === false) {
            return $filename . $suffix;
        }
        return substr($filename, 0, $dot) . $suffix . substr($filename, $dot);
    }

    /**
     * Reverse {@see self::sized_filename()}: split a `-WxH` size suffix off a requested filename.
     * Returns `['base' => full-size filename, 'width' => int, 'height' => int]`, or null when the
     * filename carries no size suffix (a full-size request).
     *
     * @return array{base:string, width:int, height:int}|null
     */
    public static function parse_size(string $filename): ?array
    {
        // The extension group is optional so an extension-less filename (suffix appended with no dot
        // by sized_filename()) still round-trips instead of falling through to a 404.
        if (!preg_match('/^(.+)-([0-9]+)x([0-9]+)(\.[^.\/]+)?$/', $filename, $m)) {
            return null;
        }
        return array(
            'base' => $m[1] . ($m[4] ?? ''),
            'width' => (int)$m[2],
            'height' => (int)$m[3],
        );
    }

    /**
     * The Edge Cache Probe URL path: the token carried as an image filename under the reserved stub id 0,
     * so it matches the same rewrite regex as a real Virtual Image URL. Reversed by
     * {@see self::parse_probe_token()} when the endpoint serves the request.
     */
    public static function probe_path(string $token): string
    {
        return self::path(self::PROBE_STUB_ID, $token . self::PROBE_EXTENSION);
    }

    /**
     * Reverse {@see self::probe_path()}: recover the probe token from a requested filename under the
     * reserved stub id. Returns null when the filename is not a `<token>.png` probe shape (so a normal
     * request under id 0 is treated as a genuine 404, never mistaken for a probe).
     */
    public static function parse_probe_token(string $filename): ?string
    {
        $ext = self::PROBE_EXTENSION;
        if (substr($filename, -strlen($ext)) !== $ext) {
            return null;
        }
        $token = substr($filename, 0, -strlen($ext));
        return $token !== '' ? $token : null;
    }

    public static function rewrite_regex(): string
    {
        return '^' . self::ROUTE . '/([0-9]+)/(.+)$';
    }

    public static function rewrite_target(): string
    {
        return 'index.php?' . self::QUERY_VAR_POST . '=$matches[1]&' . self::QUERY_VAR_FILE . '=$matches[2]';
    }
}
