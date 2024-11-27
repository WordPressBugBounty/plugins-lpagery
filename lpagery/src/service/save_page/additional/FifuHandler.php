<?php

namespace LPagery\service\save_page\additional;

use function fifu_dev_set_image;

class FifuHandler
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

    public function lpagery_handle_fifu($new_id, $raw_data)
    {
        if (array_key_exists("lpagery_fifu_url", $raw_data)) {
            if (function_exists("fifu_dev_set_image")) {
                fifu_dev_set_image($new_id, urldecode($raw_data["lpagery_fifu_url"]));
                if (array_key_exists("lpagery_fifu_alt", $raw_data)) {
                    delete_post_meta($new_id, "fifu_image_alt");
                    add_post_meta($new_id, "fifu_image_alt", sanitize_text_field($raw_data["lpagery_fifu_alt"]));
                }
            }
        }

    }

}
