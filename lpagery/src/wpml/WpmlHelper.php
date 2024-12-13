<?php
namespace LPagery\wpml;
class WpmlHelper
{
    public static function get_wpml_language_data($post_id) : WpmlLanguageData
    {
        $permalink = get_permalink($post_id);
        $language_code = null;
        if (function_exists('wpml_get_language_information')) {
            $language_details = wpml_get_language_information(null, $post_id);
            if ($language_details && !is_wp_error($language_details) && isset($language_details['language_code'])) {
                $wpml_permalink = apply_filters('wpml_permalink', $permalink, $language_details['language_code']);
                $language_code = $language_details['language_code'];
                $permalink = $wpml_permalink;
            }
        }
        return new WpmlLanguageData($language_code, $permalink);
    }

}