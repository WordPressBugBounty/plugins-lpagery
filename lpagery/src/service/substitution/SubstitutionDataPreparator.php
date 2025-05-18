<?php

namespace LPagery\service\substitution;

use Exception;

class SubstitutionDataPreparator
{
    private static $instance;

    public function __construct()
    {
        // Initialization code here
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param $data1
     *
     * @return array
     * @throws Exception
     */
    public function prepare_data($data) : array
    {
        if (empty($data)) {
            throw new Exception("DATA EMPTY");
        }
        $data = stripslashes($data);
        if (empty($data)) {
            throw new Exception("DATA EMPTY");
        }

        $json_decode = json_decode($data, true);
        if (!is_array($json_decode)) {
            throw new Exception("INVALID DATA " . $data);
        }

        return $this->recursive_sanitize_array($json_decode);
    }

    /**
     * @param array $json_decode
     * @return array|array[]
     */
    public function recursive_sanitize_array(array $json_decode): array
    {
        if (!is_array($json_decode[0] ?? null)) {
            $json_decode = array_map('urldecode', $json_decode);
            $json_decode = array_map(self::class . '::lpagery_sanitize_text', $json_decode);
        } else {
            $json_decode = array_map(function ($element) {
                $return_value = array_map('urldecode', $element);
                $return_value = array_map(self::class . '::lpagery_sanitize_text', $return_value);

                return $return_value;
            }, $json_decode);
        }
        return $json_decode;
    }

    /** @phpstan-ignore method.unused*/
    private function lpagery_sanitize_text($value)
    {
        if ($value == null) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return sanitize_url($value);
        } elseif ($this->is_HTML($value)) {
            return !current_user_can("unfiltered_html") ? wp_kses_post($value) : $value;
        } else {
            return sanitize_text_field($value);
        }
    }

    private function is_HTML($string)
    {
        if ($string != strip_tags($string)) {
            return true;
        } else {
            return false;
        }
    }


}
