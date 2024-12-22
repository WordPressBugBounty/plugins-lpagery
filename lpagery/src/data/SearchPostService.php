<?php
namespace LPagery\data;

class SearchPostService
{
    private static $instance;


    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function lpagery_search_posts($term, $types, $mode, $select, $template_id)
    {
        global $wpdb;
        if (!$term) {
            $term = "";
        }
        $term = '%' . $term . '%';
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare_in = self::lpagery_prepare_in($types);

        if ($template_id == null || $select !== "parent") {
            $template_id = 0;
        }

        if ($select === "parent") {
            $prepare_in = self::lpagery_prepare_in(array(get_post_type($template_id)));
        }

        // Initialize language join and select for WPML support
        $language_select = '';
        $language_join = '';

        if (defined('ICL_LANGUAGE_CODE')) {
            // Add language code to the select statement
            $language_select = ", icl.language_code";
            $language_join = "LEFT JOIN {$wpdb->prefix}icl_translations icl ON icl.element_id = p.ID AND icl.element_type = CONCAT('post_', p.post_type)";
        }


        $posts_with_curly_braces = $this->get_posts_with_curly_braces($select, $language_select, $language_join,
            $prepare_in, $term);
        // Build the appropriate query based on the mode and selection
        if ($mode == "update" && $select === "parent") {
            $prepare = $wpdb->prepare("SELECT p.ID, p.post_title, p.post_type $language_select 
                                    FROM {$wpdb->posts} p 
                                    $language_join
                                    WHERE p.post_title LIKE %s 
                                    AND p.post_type IN ($prepare_in) 
                                    AND p.post_status IN ('private', 'publish', 'draft') 
                                    AND NOT EXISTS (SELECT pp.id FROM $table_name_process_post pp WHERE pp.post_id = p.ID) 
                                    AND $template_id != p.ID 
                                    order by id desc
                                    LIMIT 100",
                $term);
        } elseif ($mode == "create" || $select === "parent") {
            $prepare = $wpdb->prepare("SELECT p.ID, p.post_title, p.post_type $language_select 
                                    FROM {$wpdb->posts} p 
                                    $language_join
                                    WHERE p.post_title LIKE %s 
                                    AND p.post_type IN ($prepare_in) 
                                    AND p.post_status IN ('private', 'publish', 'draft') 
                                    AND $template_id != p.ID 
                                    order by id desc
                                    LIMIT 100",
                $term);
        } else {

            $prepare = $wpdb->prepare("SELECT p.ID, p.post_title, p.post_type $language_select 
                                    FROM {$wpdb->posts} p 
                                    $language_join
                                    WHERE p.post_title LIKE %s 
                                    AND p.post_type IN ($prepare_in) 
                                    AND p.post_status IN ('private', 'publish', 'draft') 
                                    AND EXISTS (SELECT pr.id FROM $table_name_process pr WHERE pr.post_id = p.ID) 
                                    AND $template_id != p.ID 
                                    order by id desc
                                    LIMIT 100",
                $term);
        }


        // Fetch results
        $results = $wpdb->get_results($prepare);

        if($select === "template" && $mode == "create" ) {
            $results = array_filter($results, function($result) use ($posts_with_curly_braces) {
                return !in_array($result->ID, array_column($posts_with_curly_braces, 'ID'));
            });
            $results = array_merge($posts_with_curly_braces,$results);
        }

        


        return $results;
    }


    private function lpagery_prepare_in($values)
    {
        return implode(',', array_map(function ($value) {
            global $wpdb;

            // Use the official prepare() function to sanitize the value.
            return sanitize_text_field($wpdb->prepare('%s', $value));
        }, $values));
    }

    private function get_posts_with_curly_braces($select, string $language_select, string $language_join, string $prepare_in,  string $term): array
    {
        global $wpdb;
        $posts_with_curly_braces = [];

        if ($select === "template") {
            $prepare = $wpdb->prepare("SELECT p.ID, p.post_title, p.post_type $language_select 
            FROM {$wpdb->posts} p 
            $language_join
            WHERE p.post_title LIKE %s 
            and (p.post_title like '%%{%%' and p.post_title like '%%}%%')
            AND p.post_type IN ($prepare_in) 
            AND p.post_status IN ('private', 'publish', 'draft') 
            order by id desc
            LIMIT 100", $term);
            $posts_with_curly_braces = $wpdb->get_results($prepare);
        
        }
        return $posts_with_curly_braces;
    }


}