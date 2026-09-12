<?php

namespace LPagery\io\hooks;

/**
 * Registers the Image Endpoint (ADR 0013, issue #222): the `/lpagery-img/` rewrite rule + query vars,
 * the version-stamped flush on upgrade, and the early `parse_request` interception that serves Virtual
 * Image URLs. Registered unconditionally in FREE code (ADR 0009): serving must survive a lapsed
 * premium license, so no plan checks gate the render/serve path.
 */
class ImageEndpointHooks
{
    public static function register(): void
    {
        add_action('init', [self::class, 'on_init']);
        add_filter('query_vars', [self::class, 'query_vars']);
        // parse_request fires in WP::main() before the main post query, so a Virtual Image hit never
        // runs a post query (ADR 0013 — interception as early as possible, no redirects).
        add_action('parse_request', [self::class, 'parse_request']);
    }

    public static function on_init(): void
    {
        lpagery_root()->imageEndpoint()->register_rewrite_rule();
        lpagery_root()->imageEndpoint()->maybe_flush_rewrite_rules();
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public static function query_vars($vars)
    {
        return lpagery_root()->imageEndpoint()->register_query_vars(is_array($vars) ? $vars : array());
    }

    public static function parse_request($wp): void
    {
        lpagery_root()->imageEndpoint()->maybe_serve($wp);
    }
}
