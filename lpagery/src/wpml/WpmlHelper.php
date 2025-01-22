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

    public static function is_wpml_installed()
    {
        return function_exists('wpml_get_language_information') && defined('ICL_SITEPRESS_VERSION') && self::wpml_table_exists();
    }


    private static  function wpml_table_exists()
    {

        global $wpdb;
        $table_name = $wpdb->prefix . 'icl_translations';
        $dbname = $wpdb->dbname;
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT
                    TABLE_NAME
                FROM
                    information_schema.TABLES
                WHERE
                        TABLE_NAME = %s and TABLE_SCHEMA = %s
            ) as lpagery_table_exists;", $table_name, $dbname);
        $process_table_exists = $wpdb->get_results($prepare)[0]->lpagery_table_exists;
        return $process_table_exists;
    }
}