<?php

namespace LPagery\service;

class InstallationDateHandler {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function get_placeholder_counts() {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $result = $wpdb->get_row("SELECT exists(select *
              FROM INFORMATION_SCHEMA.TABLES
              WHERE table_name = '$table_name_process'
                and create_time <= '2023-09-04 00:00:00') as created");
        if ($result->created) {
            return null;
        }
        if (lpagery_fs()->is_free_plan()) {
            return 3;
        } else {
            return null;
        }
    }

    public function initial_tracking_allowed() {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $result = $wpdb->get_row("SELECT exists(select *
              FROM INFORMATION_SCHEMA.TABLES
              WHERE table_name = '$table_name_process'
                and create_time >= '2024-12-14 00:00:00') as created_after_threshold");
        return $result->created_after_threshold;
    }

    public function get_max_pages_per_run() {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $result = $wpdb->get_row("SELECT exists(select *
              FROM INFORMATION_SCHEMA.TABLES
              WHERE table_name = '$table_name_process'
                and create_time >= '2025-09-19 00:00:00') as created");
        if ($result->created && lpagery_fs()->is_free_plan()) {
            return 100;
        }
        return null;
    }
} 