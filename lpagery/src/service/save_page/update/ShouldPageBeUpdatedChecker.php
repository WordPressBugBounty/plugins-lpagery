<?php

namespace LPagery\service\save_page\update;

use LPagery\service\settings\SettingsController;
use LPagery\data\LPageryDao;
use LPagery\model\Params;
use WP_Post;

class ShouldPageBeUpdatedChecker
{
    private static $instance;

    private SettingsController $settingsController;
    private LPageryDao $lpageryDao;

    public function __construct(SettingsController $settingsController, LPageryDao $lpageryDao)
    {
        $this->settingsController = $settingsController;
        $this->lpageryDao = $lpageryDao;
    }

    public static function get_instance(SettingsController $settingsController, LPageryDao $lpageryDao)
    {
        if (null === self::$instance) {
            self::$instance = new self($settingsController, $lpageryDao);
        }
        return self::$instance;
    }

    public function should_page_be_updated(WP_Post $source_post, WP_Post $target_post, Params $params, int $process_id) : bool
    {
        $target_id = $target_post->ID;
        $process_post_data = $this->lpageryDao->lpagery_get_process_post_data($target_id);
        $post_data_changed = true;
        if ($process_post_data) {
            $existing_data = maybe_unserialize($process_post_data->data);
            $post_data_changed = $this->array_equals($params->raw_data, $existing_data, array("lpagery_ignore",
                ""));
            $source_modified = $source_post->post_modified;
            $process_post_modified = $process_post_data->modified;
            $post_data_changed = $post_data_changed || strtotime($source_modified) > strtotime($process_post_modified);
        }
        $config_changed = $this->lpageryDao->lpagery_get_process_config_changed($process_id, $target_id);
        $current_lpagery_settings = (array("spintax_enabled" => $params->spintax_enabled,
        "image_processing_enabled" => $params->image_processing_enabled));

        $previous_lpagery_settings = $this->lpageryDao->lpagery_get_process_post_global_settings($process_id,
            $target_id);

        $settings_changed = $current_lpagery_settings !== $previous_lpagery_settings;
        $consistent_update_enabled = $this->settingsController->lpagery_get_consistent_update_enabled($process_id);

        return $post_data_changed || $config_changed || $settings_changed || $consistent_update_enabled;
    }

    private function array_equals($arr1, $arr2, $ignore_fields)
    {
        foreach ($arr1 as $key => $value) {
            // Skip ignored fields
            if (in_array($key, $ignore_fields)) {
                continue;
            }

            // Replace HTML entities, trim values, and treat null and empty string as the same
            $value1 = ($value === null) ? '' : trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
            $value2 = (array_key_exists($key, $arr2) && $arr2[$key] !== null) ? trim(html_entity_decode($arr2[$key],
                ENT_QUOTES, 'UTF-8')) : '';
            $value1 = trim(str_replace(["\n",
                '\n'], '', $value1));
            $value2 = trim(str_replace(["\n",
                '\n'], '', $value2));


            if ($value1 !== $value2) {
                return true;
            }
        }

        return false;
    }
}