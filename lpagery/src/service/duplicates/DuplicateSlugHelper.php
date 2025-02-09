<?php

namespace LPagery\service\duplicates;

use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\utils\Utils;
use LPagery\service\duplicates\ExistingSlugResult;

class DuplicateSlugHelper
{
    private static $instance;

    private InputParamProvider $inputParamProvider;
    private SubstitutionHandler $substitutionHandler;
    private DynamicPageAttributeHandler $dynamicPageAttributeHandler;

    public function __construct(InputParamProvider $inputParamProvider, SubstitutionHandler $substitutionHandler, DynamicPageAttributeHandler $dynamicPageAttributeHandler)
    {
        $this->inputParamProvider = $inputParamProvider;
        $this->substitutionHandler = $substitutionHandler;
        $this->dynamicPageAttributeHandler = $dynamicPageAttributeHandler;
    }


    public static function get_instance(InputParamProvider $inputParamProvider, SubstitutionHandler $substitutionHandler, DynamicPageAttributeHandler $dynamicPageAttributeHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($inputParamProvider,$substitutionHandler, $dynamicPageAttributeHandler);
        }
        return self::$instance;
    }

    // Other methods of your class


    public function check_all_slugs_are_the_same($slugs)
    {
        if (empty($slugs) || count($slugs) == 1  ) {
            return false;
        }
        $first_slug = $slugs[0];
        foreach ($slugs as $slug) {
            if ($slug != $first_slug) {
                return false;
            }
        }
        return true;
    }

    private function check_contains_at_least_one_placeholder($value, $data, $use_sanitize = false)
    {
        if (empty($data)) {
            return false;
        }

        $array_keys = array_keys($data[0]);
        $placeholders = array_map(function ($element) use ($use_sanitize) {
            $returnValue = $element;
            if (!str_starts_with($returnValue, "{")) {
                $returnValue = "{" . $returnValue;
            }
            if (!str_ends_with($returnValue, "}")) {
                $returnValue = $returnValue . "}";
            }
            return $use_sanitize ? Utils::lpagery_sanitize_title_with_dashes($returnValue) : strtolower($returnValue);
        }, $array_keys);

        $processed_value = $use_sanitize ? Utils::lpagery_sanitize_title_with_dashes($value) : strtolower($value);

        $placeholders = array_filter($placeholders, function ($element) use ($processed_value) {
            return strpos($processed_value, $element) !== false;
        });

        return count($placeholders) > 0;
    }

    public function check_slug_contains_at_least_one_placeholder($slug, $data)
    {
        return $this->check_contains_at_least_one_placeholder($slug, $data, true);
    }

    public function check_post_title_contains_at_least_one_placeholder($title, $data)
    {
        return $this->check_contains_at_least_one_placeholder($title, $data, false);
    }

    public function lpagery_find_array_duplicates($arr,  bool  $includeParentAsIdentifier)
    {
        $duplicates = [];
        $indexes = [];

        foreach ($arr as $index => $value) {
            if (!($value instanceof ExistingSlugResult)) {
                continue;
            }
            
            // Create a unique key combining slug and parent_id
            $unique_key = $value->slug . ($includeParentAsIdentifier ? '|' .$value->parent_id : '');
            
            if (!isset($indexes[$unique_key])) {
                $indexes[$unique_key] = [];
            }
            $indexes[$unique_key][] = $index + 1;
        }

        foreach ($indexes as $unique_key => $indexArray) {
            if (count($indexArray) > 1) {
                // Extract the slug from the unique key (everything before the |)
                $slug = $includeParentAsIdentifier ? explode('|', $unique_key)[0] : $unique_key;
                $duplicates[] = [
                    'value' => $slug,
                    'rows' => $indexArray
                ];
            }
        }

        return $duplicates;
    }

    public function lpagery_find_array_numeric_values($arr)
    {
        $numeric_values = [];
        foreach ($arr as $index => $value) {
            if (!($value instanceof ExistingSlugResult)) {
                continue;
            }
            if (is_numeric($value->slug)) {
                $numeric_values[] = [
                    'value' => $value->slug,
                    'row' => $index + 2
                ];
            }
        }
        return $numeric_values;
    }

    public function lpagery_find_duplicated_slugs_with_different_parents($arr)
    {
        $slugGroups = [];

        foreach ($arr as $value) {
            if (!($value instanceof ExistingSlugResult)) {
                continue;
            }
            
            if (!isset($slugGroups[$value->slug])) {
                $slugGroups[$value->slug] = [];
            }
            $slugGroups[$value->slug][] = $value->parent_id;
        }

        foreach ($slugGroups as $parentIds) {
            if (count($parentIds) > 1 && count(array_unique($parentIds)) > 1) {
                return true;
            }
        }

        return false;
    }

    public function get_slugs_from_json_input(string $slug_from_dashboard, array $json_decode, string $post_type,?int $parent_id): array
    {
        $results = [];
        foreach ($json_decode as $index => $element) {
            $params = $this->inputParamProvider->lpagery_get_input_params_without_images($element);
            $substituted_slug = $this->substitutionHandler->lpagery_substitute_slug($params, $slug_from_dashboard);
            
            if (array_key_exists("lpagery_ignore", $element) && filter_var($element["lpagery_ignore"],
                    FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $current_parent_id = $parent_id;
            if (!empty($element["lpagery_parent"])) {
                $parent_post = $this->dynamicPageAttributeHandler->lpagery_get_parent($params, $post_type, $parent_id);
                if ($parent_post) {
                    $current_parent_id = $parent_post["id"];
                }
            }

            $results[$index] = new ExistingSlugResult(sanitize_title($substituted_slug), $current_parent_id ?? 0);
        }

        return $results;
    }
}
