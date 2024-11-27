<?php

namespace LPagery\service\save_page\additional;

use LPagery\service\substitution\SubstitutionHandler;
use LPagery\model\Params;
use LPagery\utils\Utils;
use WP_Post;

class MetaDataHandler
{

    private static $instance;
    private SubstitutionHandler $substitutionHandler;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    public static function get_instance(SubstitutionHandler $substitutionHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($substitutionHandler);
        }
        return self::$instance;
    }

    public function lpagery_copy_post_meta_info($new_id, WP_Post $template, $meta_excludelist,Params $params)
    {
        $post_meta_keys = \get_post_custom_keys($template->ID);
        if (empty($post_meta_keys)) {
            return;
        }
        if (!is_array($meta_excludelist)) {
            $meta_excludelist = [];
        }
        $meta_excludelist = \array_merge($meta_excludelist, Utils::lpagery_get_default_filtered_meta_names());


        $meta_excludelist_string = '(' . \implode(')|(', $meta_excludelist) . ')';
        if (strpos($meta_excludelist_string, '*') !== false) {
            $meta_excludelist_string = \str_replace(['*'], ['[a-zA-Z0-9_]*'], $meta_excludelist_string);

            $meta_keys = [];
            foreach ($post_meta_keys as $meta_key) {
                if (!\preg_match('#^' . $meta_excludelist_string . '$#', $meta_key)) {
                    $meta_keys[] = $meta_key;
                }
            }
        } else {
            $meta_keys = \array_diff($post_meta_keys, $meta_excludelist);
        }

        foreach ($meta_keys as $meta_key) {

            $meta_values = get_post_custom_values($meta_key, $template->ID);

            delete_post_meta($new_id, $meta_key);

            foreach ($meta_values as $meta_value) {
                $meta_value = maybe_unserialize($meta_value);

                $replacedValue = $this->substitutionHandler->lpagery_substitute($params, $meta_value);

                add_post_meta($new_id, $meta_key, Utils::lpagery_recursively_slash_strings($replacedValue));
            }
        }
        delete_post_meta($new_id, "_lpagery_page_source");
        delete_post_meta($new_id, "_lpagery_process");
        delete_post_meta($new_id, "_lpagery_plan");

        add_post_meta($new_id, "_lpagery_page_source", $template->ID);
        add_post_meta($new_id, "_lpagery_process", $params->process_id);
        add_post_meta($new_id, "_lpagery_plan", lpagery_fs()->is_free_plan() ? 'FREE' : 'PRO');

    }


}
