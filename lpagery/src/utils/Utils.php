<?php

namespace LPagery\utils;

use DateTime;

class Utils
{

    public static function lpagery_is_image_column($header)
    {
        $image_endings = ['.png',
            '.jpg',
            '.jpeg',
            '.heic',
            '.gif',
            '.svg',
            '.webp'];
        return sizeof(array_filter($image_endings, function ($element) use ($header) {
                return self::lpageryEndsWith($header, $element);
            })) > 0;
    }

    public static function lpagery_extract_post_settings($post_settings): array
    {
        $parentId = $post_settings["parent"];
        $categories = $post_settings["categories"];
        $tags = $post_settings["tags"];
        $slug = $post_settings["slug"];
        $status = $post_settings["status"];

        return array($parentId,
            $categories,
            $tags,
            $slug,
            $status);
    }

    public static function lpagery_addslashes_to_strings_only($value)
    {
        return \is_string($value) ? \addslashes($value) : $value;
    }

    public static function lpagery_recursively_slash_strings($value)
    {
        return \map_deep($value, [self::class,
            'lpagery_addslashes_to_strings_only']);
    }

    public static function lpagery_get_default_filtered_meta_names()
    {
        return ['_edit_lock',
            '_edit_last',
            '_dp_original',
            '_dp_is_rewrite_republish_copy',
            '_dp_has_rewrite_republish_copy',
            '_dp_has_been_republished',
            '_dp_creation_date_gmt',];
    }

    public static function lpagery_sanitize_object($input)
    {

        // Initialize the new array that will hold the sanitize values
        $new_input = array();

        // Loop through the input and sanitize each of the values
        foreach ($input as $key => $val) {

            $input_value = $input[$key];
            if ((isset($input_value))) {
                if (is_array($input_value)) {
                    $new_input[$key] = array_map('sanitize_text_field', $input_value);
                } else {
                    $new_input[$key] = sanitize_text_field($val);
                }
            } else {
                $new_input[$key] = '';
            }

        }

        return $new_input;

    }

    public static function lpageryEndsWith($haystack, $needle)
    {
        return substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }

    public static function lpagery_sanitize_title_with_dashes($title, $raw_title = '', $context = 'save')
    {

        $search = array("ä",
            "ü",
            "ö");

        $replace = array("ae",
            "ue",
            "oe");

        $title = str_replace($search, $replace, $title);

        $title = strip_tags($title);
        // Preserve escaped octets.
        $title = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title);
        // Remove percent signs that are not part of an octet.
        $title = str_replace('%', '', $title);
        // Restore octets.
        $title = preg_replace('|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title);

        if (seems_utf8($title)) {
            if (function_exists('mb_strtolower')) {
                $title = mb_strtolower($title, 'UTF-8');
            }
            $title = utf8_uri_encode($title, 200);
        }

        $title = strtolower($title);

        if ('save' === $context) {
            // Convert &nbsp, &ndash, and &mdash to hyphens.
            $title = str_replace(array('%c2%a0',
                '%e2%80%93',
                '%e2%80%94'), '-', $title);
            // Convert &nbsp, &ndash, and &mdash HTML entities to hyphens.
            $title = str_replace(array('&nbsp;',
                '&#160;',
                '&ndash;',
                '&#8211;',
                '&mdash;',
                '&#8212;'), '-', $title);
            // Convert forward slash to hyphen.
            $title = str_replace('/', '-', $title);

            // Strip these characters entirely.
            $title = str_replace(array(// Soft hyphens.
                '%c2%ad',
                // &iexcl and &iquest.
                '%c2%a1',
                '%c2%bf',
                // Angle quotes.
                '%c2%ab',
                '%c2%bb',
                '%e2%80%b9',
                '%e2%80%ba',
                // Curly quotes.
                '%e2%80%98',
                '%e2%80%99',
                '%e2%80%9c',
                '%e2%80%9d',
                '%e2%80%9a',
                '%e2%80%9b',
                '%e2%80%9e',
                '%e2%80%9f',
                // Bullet.
                '%e2%80%a2',
                // &copy, &reg, &deg, &hellip, and &trade.
                '%c2%a9',
                '%c2%ae',
                '%c2%b0',
                '%e2%80%a6',
                '%e2%84%a2',
                // Acute accents.
                '%c2%b4',
                '%cb%8a',
                '%cc%81',
                '%cd%81',
                // Grave accent, macron, caron.
                '%cc%80',
                '%cc%84',
                '%cc%8c',), '', $title);

            // Convert &times to 'x'.
            $title = str_replace('%c3%97', 'x', $title);
        }

        // Kill entities.
        $title = preg_replace('/&.+?;/', '', $title);
        $title = str_replace('.', '-', $title);

        $title = preg_replace('/[^%a-z0-9 {}_-]/', '', $title);
        $title = preg_replace('/\s+/', '-', $title);
        $title = preg_replace('|-+|', '-', $title);
        $title = trim($title, '-');
        return $title;

    }

    public static function lpagery_time_ago($timestamp)
    {
        $current_time = new DateTime();
        $time_to_compare = DateTime::createFromFormat('U', $timestamp);
        $time_difference = $current_time->getTimestamp() - $time_to_compare->getTimestamp();

        $is_future = ($time_difference < 0);
        $time_difference = abs($time_difference);

        $units = ["year" => 365 * 24 * 60 * 60,
            "month" => 30 * 24 * 60 * 60,
            "week" => 7 * 24 * 60 * 60,
            "day" => 24 * 60 * 60,
            "hour" => 60 * 60,
            "minute" => 60,
            "second" => 1];

        foreach ($units as $unit => $value) {
            if ($time_difference >= $value) {
                $unit_value = floor($time_difference / $value);
                $suffix = ($unit_value == 1) ? "" : "s";
                $direction = ($is_future) ? "from now" : "ago";
                return "$unit_value $unit$suffix $direction";
            }
        }

        return "just now";
    }

    public static function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public static function get_uncached_option($option_name)
    {
        global $wpdb;
        $option_value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1",
            $option_name));
        return maybe_unserialize($option_value);
    }

    public static function is_base_64_encoded($string)
    {
        return base64_encode(base64_decode($string, true)) === $string;
    }
}
