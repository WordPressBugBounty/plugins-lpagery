<?php

namespace LPagery\service\settings;

use LPagery\data\LPageryDao;
use WP_Post_Type;

class SettingsController
{
    private static $instance;

    const OPTION_GOOGLE_SHEET_SYNC_ENABLED = "lpagery_google_sheet_sync_enabled";
    const OPTION_GOOGLE_SHEET_SYNC_FORCE_UPDATE = "lpagery_google_sheet_sync_force_update";
    const OPTION_SYNC_BATCH_SIZE = "lpagery_sync_batch_size";
    const OPTION_SYNC_OVERWRITE_MANUAL_CHANGES = "lpagery_sync_overwrite_manual_changes";
    const OPTION_GOOGLE_SHEET_SYNC_INTERVAL = "lpagery_google_sheet_sync_interval";

    /**
     * Singleton pattern implementation
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Filters out default WordPress post types
     */
    public function filterPostType(WP_Post_Type $type): bool
    {
        $excludedTypes = [
            "post",
            "page",
            "attachment",
            "revision",
            "nav_menu_item",
            "custom_css",
            "customize_changeset",
            "oembed_cache",
            "user_request",
            "wp_block",
            "wp_template",
            "wp_template_part",
            "wp_global_styles",
            "wp_navigation",
        ];
        return !in_array($type->name, $excludedTypes, true);
    }

    /**
     * Retrieves custom post types
     */
    public function getAvailablePostTypes(): array
    {
        $postTypes = get_post_types(['public' => true], "objects");
        $filtered = array_filter($postTypes, [$this,
            'filterPostType']);
        $result = array_map(function ($type) {
            return ["name"  => $type->name, "label" => $type->label];
        }, $filtered);
        
        return array_values($result);
    }

    /**
     * Saves the settings
     */
    public function saveSettings(Settings $settings): void
    {
        if($settings->hierarchical_taxonomy_handling !=='all' && $settings->hierarchical_taxonomy_handling !=='last') {
            throw new \InvalidArgumentException('Invalid hierarchical taxonomy handling value');
        }
        $userId = get_current_user_id();

        // Update user-specific settings
        $userSettings = [
            'spintax' => $settings->spintax,
            'image_processing' => $settings->image_processing,
            'custom_post_types' => $settings->custom_post_types,
            'hierarchical_taxonomy_handling' => $settings->hierarchical_taxonomy_handling,
            'author_id' => $settings->author_id,
        ];

        update_user_option($userId, 'lpagery_settings', $userSettings, false);

        // Update global settings - store as '1' or '' for better WordPress compatibility
        update_option(self::OPTION_GOOGLE_SHEET_SYNC_ENABLED, 
            filter_var($settings->google_sheet_sync_enabled, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        update_option(self::OPTION_GOOGLE_SHEET_SYNC_FORCE_UPDATE, 
            filter_var($settings->google_sheet_sync_force_update, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        update_option(self::OPTION_SYNC_BATCH_SIZE, $settings->sync_batch_size);
        update_option(self::OPTION_SYNC_OVERWRITE_MANUAL_CHANGES,
            filter_var($settings->google_sheet_sync_overwrite_manual_changes, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');

        // Handle Google Sheet sync interval and scheduling
        $currentInterval = get_option(self::OPTION_GOOGLE_SHEET_SYNC_INTERVAL);

        if (!$currentInterval) {
            add_option(self::OPTION_GOOGLE_SHEET_SYNC_INTERVAL, $settings->google_sheet_sync_interval);
            do_action('lpagery_google_sheet_schedule_changed', $settings->next_google_sheet_sync);
        } else {
            $oldTimestamp = wp_next_scheduled("lpagery_sync_google_sheet");

            if (
                $currentInterval !== $settings->google_sheet_sync_interval ||
                $oldTimestamp != $settings->next_google_sheet_sync
            ) {
                update_option(self::OPTION_GOOGLE_SHEET_SYNC_INTERVAL, $settings->google_sheet_sync_interval);
                do_action('lpagery_google_sheet_schedule_changed', $settings->next_google_sheet_sync);
            }
        }
    }

    /**
     * Retrieves the settings
     */
    public function getSettings(): Settings
    {
        if ($this->isLimitedPlan()) {
            return $this->getLimitedPlanSettings();
        }

        $userId = get_current_user_id();
        $userOptions = maybe_unserialize(get_user_option('lpagery_settings', $userId));

        if (empty($userOptions)) {
            return $this->getDefaultSettings();
        }

        return $this->createSettingsFromUserOptions($userOptions);
    }

    /**
     * Retrieves default settings
     */
    private function getDefaultSettings(): Settings
    {
        $userOptions = [
            'spintax' => false,
            'image_processing' => lpagery_fs()->is_plan_or_trial("extended"),
            'custom_post_types' => [],
            'author_id' => get_current_user_id(),
        ];

        return $this->createSettingsFromUserOptions($userOptions);
    }
    private function getLimitedPlanSettings(): Settings
    {
        $settings = new Settings();
        $settings->spintax = false;
        $settings->image_processing = false;
        $settings->custom_post_types = [];
        $settings->author_id = get_current_user_id();
        $settings->google_sheet_sync_interval = "hourly";
        $settings->sync_batch_size = 0;
        $settings->next_google_sheet_sync = null;
        $settings->google_sheet_sync_force_update = false;
        $settings->google_sheet_sync_overwrite_manual_changes = false;
        $settings->google_sheet_sync_enabled = false;
        $settings->hierarchical_taxonomy_handling = 'last';
        $settings->wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        return $settings;
    }

    /**
     * Checks if the user is on a limited plan
     */
    private function isLimitedPlan(): bool
    {
        return lpagery_fs()->is_free_plan() || lpagery_fs()->is_plan_or_trial("standard", true);
    }

    /**
     * Creates a Settings object from user options
     */
    private function createSettingsFromUserOptions(array $userOptions): Settings
    {
        $custom_post_types  = $userOptions['custom_post_types'];
        if(!is_array($custom_post_types)) {
            $custom_post_types = [];
        }
        $custom_post_types = array_values($custom_post_types);
        $settings = new Settings();
        $settings->spintax = filter_var($userOptions['spintax'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $settings->image_processing = filter_var($userOptions['image_processing'] ?? lpagery_fs()->is_plan_or_trial("extended"), FILTER_VALIDATE_BOOLEAN);
        $settings->custom_post_types = $custom_post_types;
        $settings->hierarchical_taxonomy_handling = $userOptions['hierarchical_taxonomy_handling'] ?? 'last';
        $settings->author_id = $userOptions['author_id'] ?? get_current_user_id();
        $settings->google_sheet_sync_interval = get_option(self::OPTION_GOOGLE_SHEET_SYNC_INTERVAL, "hourly");
        $settings->google_sheet_sync_enabled = filter_var($this->getSheetSyncEnabled(), FILTER_VALIDATE_BOOLEAN);
        $settings->sync_batch_size = $this->getBatchSize();
        $settings->next_google_sheet_sync = $this->getNextGoogleSheetSync();
        $settings->google_sheet_sync_force_update = filter_var($this->isForceUpdateEnabled(), FILTER_VALIDATE_BOOLEAN);
        $settings->google_sheet_sync_overwrite_manual_changes = filter_var($this->isOverwriteManualChangesEnabled(), FILTER_VALIDATE_BOOLEAN);
        $settings->wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        return $settings;
    }


    /**
     * Retrieves the Google Sheet sync type
     */
    public function getSheetSyncEnabled(): bool
    {
        if ($this->isLimitedPlan()) {
            return false;
        }
        return filter_var(get_option(self::OPTION_GOOGLE_SHEET_SYNC_ENABLED, '1'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Retrieves the batch size
     */
    public function getBatchSize(): int
    {
        return intval (get_option(self::OPTION_SYNC_BATCH_SIZE, 1000));
    }

    /**
     * Checks if force update is enabled
     */
    public function isForceUpdateEnabled(): bool
    {
        return (bool)get_option(self::OPTION_GOOGLE_SHEET_SYNC_FORCE_UPDATE, false);
    }

    /**
     * Checks if manual changes overwrite is enabled
     */
    public function isOverwriteManualChangesEnabled(): bool
    {
        return (bool)get_option(self::OPTION_SYNC_OVERWRITE_MANUAL_CHANGES, false);
    }

    /**
     * Checks if image processing is enabled
     */
    public function isImageProcessingEnabled($processId = null): bool
    {
        $userId = $this->getUserId($processId);
        $userSettings = $this->getUserSettings($userId);

        return filter_var($userSettings['image_processing'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Checks if spintax is enabled
     */
    public function isSpintaxEnabled($processId = null): bool
    {
        $userId = $this->getUserId($processId);
        $userSettings = $this->getUserSettings($userId);

        return filter_var($userSettings['spintax'], FILTER_VALIDATE_BOOLEAN);
    }

    public function getHierarchicalTaxonomyHandling($processId = null): string
    {
        $userId = $this->getUserId($processId);
        $userSettings = $this->getUserSettings($userId);

        $hierarchical_taxonomy_handling = $userSettings['hierarchical_taxonomy_handling'];
        if(!$hierarchical_taxonomy_handling || !in_array($hierarchical_taxonomy_handling, ['all', 'last'])) {
            return 'last';
        }
        return $hierarchical_taxonomy_handling;
    }

    /**
     * Retrieves the author ID
     */
    public function getAuthorId($processId = null): int
    {
        $userId = $this->getUserId($processId);
        $userSettings = $this->getUserSettings($userId);

        return (int)$userSettings['author_id'];
    }

    /**
     * Retrieves custom post types
     */
    public function getEnabledCustomPostTypes($processId = null): array
    {
        $userId = $this->getUserId($processId);
        $userSettings = $this->getUserSettings($userId);

        return $userSettings['custom_post_types'] ?? [];
    }

    /**
     * Retrieves the user ID
     */
    private function getUserId($processId = null): int
    {
        $userId = get_current_user_id();

        if (!$userId && $processId) {
            $lpageryDao = LPageryDao::get_instance();
            $process = $lpageryDao->lpagery_get_process_by_id($processId);
            if (!empty($process)) {
                $userId = $process->user_id;
            } else {
                $userId = 0;
            }
        }

        return $userId;
    }

    /**
     * Retrieves user settings
     */
    private function getUserSettings($userId = null): array
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        $userOptions = maybe_unserialize(get_user_option('lpagery_settings', $userId));

        if (empty($userOptions)) {
            $userOptions = [
                'spintax' => false,
                'image_processing' => lpagery_fs()->is_plan_or_trial("extended"),
                'custom_post_types' => [],
                'hierarchical_taxonomy_handling' => 'last',
                'author_id' => get_current_user_id(),
            ];
        }

        return $userOptions;
    }

    /**
     * Retrieves the next Google Sheet sync time
     */
    private function getNextGoogleSheetSync(): ?string
    {
        $timestamp = wp_next_scheduled("lpagery_sync_google_sheet");
        if ($timestamp) {
            $gmtDate = gmdate('Y-m-d\TH:i:s.Z\Z', $timestamp);
            return get_date_from_gmt($gmtDate, 'Y-m-d\TH:i');
        }
        return null;
    }
}
