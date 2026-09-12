<?php

namespace LPagery\service\live_render;

/**
 * Thin seam over `function_exists`/`defined` so {@see LiveCachePurger} can probe for cache plugins
 * without the probe being a static language construct — which lets tests declare a plugin present or
 * absent independently of what functions the test runner happens to have defined.
 */
class CachePluginDetector
{
    public function function_available(string $name): bool
    {
        return function_exists($name);
    }

    public function constant_defined(string $name): bool
    {
        return defined($name);
    }

    public function class_available(string $name): bool
    {
        return class_exists($name);
    }

    /**
     * Whether the active object-cache drop-in supports an optional feature (`flush_group`,
     * `flush_runtime`, …). Probed through the detector for the same reason as the plugin functions:
     * a test can declare group flushing available or missing without a real drop-in.
     */
    public function cache_supports(string $feature): bool
    {
        return function_exists('wp_cache_supports') && wp_cache_supports($feature);
    }
}
