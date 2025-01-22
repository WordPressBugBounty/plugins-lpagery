<?php

namespace LPagery\service\save_page\additional;

use LPagery\wpml\WpmlHelper;
use WPML_Terms_Translations;

class WpmlHandler
{
    private static $instance;

    private function __construct()
    {
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function lpagery_handle_wpml($sourcePostId, $targetPostId)
    {
        if (!WpmlHelper::is_wpml_installed()) {
            return;
        }
        $template_language_info = wpml_get_language_information(null, $sourcePostId);
        if ($template_language_info) {
            $get_language_args = array('element_id' => $sourcePostId,
                'element_type' => get_post_type($sourcePostId));
            $original_post_language_info = apply_filters('wpml_element_language_details', null, $get_language_args);
            if ($original_post_language_info) {
                self::wpml_switch_post_language($original_post_language_info->language_code, $targetPostId);
            }
        }
    }

    private function wpml_switch_post_language($language, $post_id)
    {
        global $sitepress;

        if ($post_id && $language) {
            $post_type = get_post_type($post_id);
            $wpml_post_type = 'post_' . $post_type;
            $trid = $sitepress->get_element_trid($post_id, $wpml_post_type);

            /* Check if a translation in that language already exists with a different post id.
             * If so, then don't perform this action.
             */
            $sitepress->set_element_language_details($post_id, $wpml_post_type, $trid, $language);
            // Synchronize the posts terms languages. Do not create automatic translations though.
            WPML_Terms_Translations::sync_post_terms_language($post_id);
            require_once WPML_PLUGIN_PATH . '/inc/cache.php';
            icl_cache_clear($post_type . 's_per_language', true);

            if (method_exists('\WPML\LIB\WP\Cache', 'clearMemoizedFunction')) {
                \WPML\LIB\WP\Cache::clearMemoizedFunction('get_source_language_by_trid', (int)$trid);
            }
        }

    }

}
