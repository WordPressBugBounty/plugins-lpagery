<?php

namespace LPagery\utils;

class ElementorCacheUtils
{
    public static function clearCache(): void
    {
        try {
            if (class_exists('\Elementor\Plugin')) {
                $plugin = \Elementor\Plugin::$instance;
                if ($plugin != null) {
                    $plugin->files_manager->clear_cache();
                }
            }
        } catch (\Throwable $e) {
            error_log($e->__toString());
        }
    }
}
