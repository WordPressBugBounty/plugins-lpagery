<?php

namespace LPagery\io;

use LPagery\wpml\WpmlHelper;

class Mapper
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

    public function lpagery_map_post($post)
    {
        $result = array("id" => $post->ID,
            "title" => $post->post_title);
            
        if (isset($post->post_type)) {
            $post_type = get_post_type_object($post->post_type);
            if ($post_type) {
                $result["type_label"] = $post_type->labels->singular_name;
                $result["type"] = $post_type->name;
            }
        }

        if (isset($post->language_code)) {
            $result["language_code"] = $post->language_code;
        }
        return $result;
    }

    public function lpagery_map_post_extended($post)
    {
        if (!is_array($post)) {
            $post = (array)$post;
        }
        $wpmlInfo = WpmlHelper::get_wpml_language_data($post["ID"]);

        $array = array("id" => $post["ID"],
            "title" => $post["post_title"],
            "process_id" => $post["process_id"],
            "slug" => $post["replaced_slug"],
            "permalink" => get_permalink($post["ID"]));
        if($wpmlInfo->language_code){
            $array["language_code"] = $wpmlInfo->language_code;
        }
        return $array;
    }

    public function lpagery_map_post_for_update_modal($post)
    {
        if (!is_array($post)) {
            $post = (array)$post;
        }
        $wpmlInfo = WpmlHelper::get_wpml_language_data($post["ID"]);

        $array = array("id" => $post["ID"],
            "title" => $post["post_title"],
            "process_id" => $post["process_id"],
            "parent" => $post["parent_id"],
            "status" => $post["post_status"],
            "template" => $post["template_id"],
            "slug" => $post["replaced_slug"],
            "permalink" => get_permalink($post["ID"]),
            "page_manually_updated_at" => $post["page_manually_updated_at"],
            "taxonomies" => array_filter(json_decode($post["taxonomies"]), function ($taxonomy) {
                return isset($taxonomy->id);
            }));
        if($wpmlInfo->language_code){
            $array["language_code"] = $wpmlInfo->language_code;
        }
        if(isset($post["page_manually_updated_by"])) {
            $WP_User = get_user_by("id", $post["page_manually_updated_by"]);
            if($WP_User) {
                $array["page_manually_updated_by"] = [
                    "name" => $WP_User->display_name,
                    "email" => $WP_User->user_email
                ];
            }

        }
        return $array;
    }

    private function get_google_sheet_data($lpagery_process)
    {
        $data = maybe_unserialize($lpagery_process->google_sheet_data);
        if ($data === null) {
            return [
                "add" => false,
                "update" => false,
                "delete" => false
            ];
        }
        
        // Ensure the data matches the schema
        $validated_data = [
            "add" => isset($data["add"]) ? (bool)$data["add"] : false,
            "update" => isset($data["update"]) ? (bool)$data["update"] : false,
            "delete" => isset($data["delete"]) ? (bool)$data["delete"] : false
        ];
        
        // Only include url if it's a valid URL
        if (isset($data["url"]) && filter_var($data["url"], FILTER_VALIDATE_URL)) {
            $validated_data["url"] = $data["url"];
        }
        
        return $validated_data;
    }

    public function lpagery_map_process($lpagery_process)
    {
        $user = get_user_by("id", $lpagery_process->user_id);
        $phpdate = strtotime($lpagery_process->created);
        $mysqldate = date('Y-m-d', $phpdate);
        if (empty($lpagery_process->purpose)) {
            $post_type = ucfirst(get_post_type($lpagery_process->post_id));
            $purpose_text = $post_type . " Creation by " . $user->display_name . " at " . $mysqldate;
        } else {
            $purpose_text = $lpagery_process->purpose . " by " . $user->display_name;
        }
        [$next_sync,
            $last_sync,
            $status] = $this->get_google_sheet_sync_details($lpagery_process);

        return array("id" => $lpagery_process->id,
            "post_id" => $lpagery_process->post_id,
            "user" => array("name" => $user->display_name,
                "email" => $user->user_email),
            "post_count" => $lpagery_process->count,
            "display_purpose" => $purpose_text,
            "google_sheet_data" => maybe_unserialize(self::get_google_sheet_data($lpagery_process)),
            "raw_purpose" => $lpagery_process->purpose,
            "google_sheet_sync_error" => $lpagery_process->google_sheet_sync_error,
            "next_google_sheet_sync" => $next_sync,
            "last_google_sheet_sync" => $last_sync,
            "google_sheet_sync_status" => $status,
            "queue_count" => $lpagery_process->queue_count,
            "processed_queue_count" => $lpagery_process->processed_queue_count,
            "google_sheet_sync_enabled" => filter_var($lpagery_process->google_sheet_sync_enabled,
                FILTER_VALIDATE_BOOLEAN),
            "created" => $mysqldate);
    }

    public function lpagery_map_process_search($lpagery_process)
    {
        $phpdate = strtotime($lpagery_process->created);
        $user = get_user_by("id", $lpagery_process->user_id);

        $mysqldate = date('Y-m-d', $phpdate);
        $post = get_post($lpagery_process->post_id);
        if ($post) {
            $title = $post->post_title;
            if($post->post_status === "trash") {
                $title = "Trashed (" .  $post->post_title . ")";
            }
            $post_array = array("title" => $title,
                "permalink" => get_permalink($post),
                "type" => get_post_type($post),
                "deleted" => $post->post_status === "trash");

            $wpmlLanguageData = WpmlHelper::get_wpml_language_data($lpagery_process->post_id);
            if ($wpmlLanguageData->language_code) {
                $post_array['language_code'] = $wpmlLanguageData->language_code;
                $post_array['permalink'] = $wpmlLanguageData->permalink;
            }
        } else {
            $post_array = array("title" => "Deleted (ID: " . $lpagery_process->post_id . ")",
                "deleted" => true);
        }

        [$next_sync,
            $last_sync,
            $status] = $this->get_google_sheet_sync_details($lpagery_process);
        $google_sheet_url = null;
        if (isset($lpagery_process->google_sheet_data)) {
            $sheet_data = maybe_unserialize($lpagery_process->google_sheet_data);
            if ($sheet_data && isset($sheet_data["url"]) && filter_var($sheet_data["url"], FILTER_VALIDATE_URL)) {
                $google_sheet_url = $sheet_data["url"];
            }
        }

        if (empty($lpagery_process->purpose)) {
            $post_type = ucfirst(get_post_type($lpagery_process->post_id));
            $purpose_text = $post_type . " creation set created at " . $mysqldate;
        } else {
            $purpose_text = $lpagery_process->purpose;
        }

        return array("id" => $lpagery_process->id,
            "post_id" => $lpagery_process->post_id,
            "user_id" => $lpagery_process->user_id,
            "errored" => $lpagery_process->errored,
            "in_queue" => $lpagery_process->in_queue,
            "user" => array("name" => $user->display_name,
                "email" => $user->user_email),
            "post_count" => $lpagery_process->count,
            "display_purpose" => $lpagery_process->purpose,
            "purpose_with_name" => $purpose_text,
            "google_sheet_sync_enabled" => filter_var($lpagery_process->google_sheet_sync_enabled,
                FILTER_VALIDATE_BOOLEAN),
            "created" => $mysqldate,
            "next_google_sheet_sync" => $next_sync,
            "last_google_sheet_sync" => $last_sync,
            "google_sheet_sync_status" => $status,
            "google_sheet_url" => $google_sheet_url,
            "post" => $post_array);
    }


    public function lpagery_map_process_update_details($lpagery_process, $data)
    {
        $mapped_process = $this->lpagery_map_process($lpagery_process);
        $mapped_data = array_map(function ($element) {

            $unserialized = maybe_unserialize($element->data);
            if (property_exists($element, "permalink")) {
                $unserialized['permalink'] = ($element->permalink);
            }

            return $unserialized;
        }, $data);

        $unserialized_data = maybe_unserialize($lpagery_process->data);
        if (!array_key_exists("taxonomy_terms", $unserialized_data)) {
            $tag_ids = array_map(function ($tag) {
                $term = get_term_by("name", $tag, "post_tag");
                return $term ? $term->term_id : null;
            }, $unserialized_data["tags"] ?? []);

            // Filter out any null values from non-existent terms
            $cat_ids = array_filter($unserialized_data["categories"] );
            $tag_ids = array_filter($tag_ids);

            $unserialized_data["taxonomy_terms"] = ["category" => $cat_ids,
                "post_tag" => $tag_ids];
        }
        if (empty($unserialized_data["taxonomy_terms"])) {
            unset($unserialized_data["taxonomy_terms"]);
        }
        return array("process" => $mapped_process,
            "data" => $mapped_data,
            "config_data" => $unserialized_data,
            "google_sheet_sync_enabled" => $lpagery_process->google_sheet_sync_enabled,
            "google_sheet_data" => $this->get_google_sheet_data($lpagery_process)
        );
    }

    private function get_google_sheet_sync_details($process)
    {
        $status = $process->google_sheet_sync_status;
        $next_sync = wp_next_scheduled("lpagery_sync_google_sheet");
        $last_sync = strtotime($process->last_google_sheet_sync);
        if (!$last_sync) {
            $last_sync = null;
        }

        $current_time = current_time('U', true);
        $interval = wp_get_schedules()[get_option("lpagery_google_sheet_sync_interval", "hourly")]["interval"];


        $time_difference_next_sync = $next_sync - $current_time;
        $time_difference_last_sync = $current_time - $last_sync;

        if (!$status) {
            $status = "PLANNED";
        }

        // if the last sync happened more than 15 minutes ago
        if ($time_difference_last_sync > 900) {
            // 15 minutes
            $past_due_threshold = $interval + 900;
            if ($time_difference_next_sync >= 0 || !$process->google_sheet_sync_enabled) {
                return [$next_sync,
                    $last_sync,
                    $status];
            }
            if ($time_difference_next_sync < 0 && abs($time_difference_next_sync) <= $past_due_threshold) {
                $status = "PLANNED";
            } elseif ($time_difference_next_sync < 0 && abs($time_difference_next_sync) > $past_due_threshold) {
                $status = "PAST_DUE";
            }
        }
        global $wpdb;
        $exists_error = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lpagery_sync_queue WHERE error is not null  AND process_id = {$process->id}");
        $exists_pending = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lpagery_sync_queue WHERE error is null  AND process_id = {$process->id}");

        if($status === "RUNNING") {
            if(!$exists_pending) {
                $status = "FINISHED";
            }
        }

        if($status === "FINISHED") {

            if($exists_error) {
                $status = "ERROR";
            }

            if($exists_pending) {
                $status = "WAITING_FOR_PROCESSING";
            }

        }


        return [$next_sync,
            $last_sync,
            $status];

    }

}
