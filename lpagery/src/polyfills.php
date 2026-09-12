<?php
// Polyfills for PHP < 8.0 string functions. Delete this file when the PHP floor rises.
    if (!function_exists('str_contains')) {
        function str_contains($haystack, $needle)
        {
            return ('' === $needle || false !== strpos($haystack, $needle));
        }
    }
    if (!function_exists('str_starts_with')) {
        function str_starts_with($haystack, $needle)
        {
            if ('' === $needle) {
                return true;
            }

            return 0 === strpos($haystack, $needle);
        }
    }
    if (!function_exists('str_ends_with')) {

        function str_ends_with($haystack, $needle)
        {
            if ('' === $haystack && '' !== $needle) {
                return false;
            }

            $len = strlen($needle);

            return 0 === substr_compare($haystack, $needle, -$len, $len);
        }

    }
