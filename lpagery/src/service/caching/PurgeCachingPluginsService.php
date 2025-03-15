<?php

namespace LPagery\service\caching;


class PurgeCachingPluginsService
{

    private static ?PurgeCachingPluginsService $instance = null;

    public static function get_instance(): PurgeCachingPluginsService
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function purge_caching_plugins($post_id)
    {
        try {
            // WP Rocket
            if (function_exists('rocket_clean_post')) {
                rocket_clean_post($post_id);
            }

            // W3 Total Cache
            if (function_exists('w3tc_flush_post')) {
                w3tc_flush_post($post_id);
            }

            // WP Super Cache
            if (function_exists('wp_cache_post_change')) {
                wp_cache_post_change($post_id);
            }

            // WP Fastest Cache
            if (function_exists('wpfc_clear_post_cache_by_id')) {
                wpfc_clear_post_cache_by_id($post_id);
            }

            // LiteSpeed Cache
            do_action('litespeed_purge_post', $post_id);


            // Fallback: Clear the post cache using WordPress core function
            clean_post_cache($post_id);
        } catch (\Exception $e) {
            error_log("Error while purging caching plugins: " . $e->getMessage());
        }
    }


}