<?php

namespace LPagery\service\settings;

use LPagery\data\repository\PageSetRepository;
use LPagery\service\image_endpoint\EdgeCacheProbe;
use LPagery\service\image_endpoint\EdgeCacheProbeStore;
use LPagery\service\live_render\LivePagePurger;
use WP_Post_Type;

class SettingsController
{
    private PageSetRepository $pageSetRepository;
    private LivePagePurger $livePagePurger;
    private EdgeCacheProbeStore $edgeCacheProbeStore;

    const OPTION_GOOGLE_SHEET_SYNC_ENABLED = "lpagery_google_sheet_sync_enabled";
    const OPTION_GOOGLE_SHEET_SYNC_FORCE_UPDATE = "lpagery_google_sheet_sync_force_update";
    const OPTION_SYNC_BATCH_SIZE = "lpagery_sync_batch_size";
    const OPTION_SYNC_OVERWRITE_MANUAL_CHANGES = "lpagery_sync_overwrite_manual_changes";
    const OPTION_GOOGLE_SHEET_SYNC_INTERVAL = "lpagery_google_sheet_sync_interval";
    const OPTION_HIDE_GENERATED_PAGES = "lpagery_hide_generated_pages";
    const OPTION_DEFAULT_RENDER_MODE = "lpagery_default_render_mode";
    const OPTION_DEFAULT_BACKGROUND_GENERATION = "lpagery_default_background_generation";
    // Persisted UI toggle for the site-wide Virtual Image URLs setting (ADR 0015). Distinct key from
    // the identically-purposed `lpagery_virtual_images_enabled` *filter* (the code-only override) so the
    // two AND-inputs in VirtualImageAvailability::site_wide_enabled() never share a literal string.
    const OPTION_VIRTUAL_IMAGES_ENABLED = "lpagery_virtual_image_urls_enabled";

    public function __construct(
        PageSetRepository $pageSetRepository,
        LivePagePurger $livePagePurger,
        EdgeCacheProbeStore $edgeCacheProbeStore
    ) {
        $this->pageSetRepository = $pageSetRepository;
        $this->livePagePurger = $livePagePurger;
        $this->edgeCacheProbeStore = $edgeCacheProbeStore;
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
            'image_partial_match' => $settings->image_partial_match,
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
        update_option(self::OPTION_HIDE_GENERATED_PAGES,
            filter_var($settings->hide_generated_pages, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        update_option(self::OPTION_DEFAULT_RENDER_MODE, $this->sanitizeRenderMode($settings->default_render_mode));
        update_option(self::OPTION_DEFAULT_BACKGROUND_GENERATION,
            filter_var($settings->default_background_generation, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');

        // Site-wide Virtual Image URLs setting (ADR 0015). Flipping it changes the site-wide render shape,
        // so on a *changed* value purge every live page's cached HTML through the shared issue-228 seam so
        // it converges to the new shape instead of waiting for expiry; an unchanged save purges nothing.
        $previousVirtualImagesEnabled = $this->isVirtualImagesEnabled();
        $newVirtualImagesEnabled = filter_var($settings->virtual_images_enabled, FILTER_VALIDATE_BOOLEAN);
        update_option(self::OPTION_VIRTUAL_IMAGES_ENABLED, $newVirtualImagesEnabled ? '1' : '0');
        if ($newVirtualImagesEnabled !== $previousVirtualImagesEnabled) {
            $this->livePagePurger->purge_all_live_pages();
        }

        // An explicit save is a permanent choice, so end the Edge Cache Probe (issue #233): the persisted
        // option above already makes every future probe run a no-op, and clearing the scheduled hook removes
        // any pending one-off event so no probe fires after the operator has decided the setting by hand.
        // Guarded on the raw option actually holding a value: if the write above failed, the setting is
        // still undecided and the pending probe retry must survive to decide it.
        if (get_option(self::OPTION_VIRTUAL_IMAGES_ENABLED, false) !== false) {
            wp_clear_scheduled_hook(\LPagery\io\hooks\EdgeCacheProbeHooks::PROBE_HOOK);
        }

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
     * The persisted custom post types, unmasked by plan. `getSettings()` hides them below Extended,
     * so a save on a limited plan reads this to keep what is stored instead of writing the mask back.
     *
     * @return string[]
     */
    public function getStoredCustomPostTypes(): array
    {
        $custom_post_types = $this->getUserSettings()['custom_post_types'] ?? [];
        if (!is_array($custom_post_types)) {
            return [];
        }
        return array_values(array_filter($custom_post_types, 'is_string'));
    }

    /**
     * Retrieves default settings
     */
    private function getDefaultSettings(): Settings
    {
        $userOptions = [
            'spintax' => false,
            'image_processing' => lpagery_fs()->is_plan_or_trial("extended"),
            'image_partial_match' => true,
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
        $settings->image_partial_match = true;
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
        $settings->hide_generated_pages = filter_var(get_option(self::OPTION_HIDE_GENERATED_PAGES, '0'), FILTER_VALIDATE_BOOLEAN);
        // Live Mode creation is Extended-only, so a limited plan always defaults to Classic.
        $settings->default_render_mode = 'classic';
        // Background Generation is Extended-only, so a limited plan can never pre-select it.
        $settings->default_background_generation = false;
        // Virtual Image URLs is a site-wide render setting with no premium gate (ADR 0015/0009), so a
        // limited plan reads the real persisted value like any other global toggle.
        $settings->virtual_images_enabled = $this->isVirtualImagesEnabled();
        $settings->virtual_images_detection = $this->getVirtualImagesDetection();

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
        $settings->image_partial_match = filter_var($userOptions['image_partial_match'] ?? true, FILTER_VALIDATE_BOOLEAN);
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
        $settings->hide_generated_pages = filter_var(get_option(self::OPTION_HIDE_GENERATED_PAGES, '0'), FILTER_VALIDATE_BOOLEAN);
        $settings->default_render_mode = $this->getDefaultRenderMode();
        $settings->default_background_generation = $this->getDefaultBackgroundGeneration();
        $settings->virtual_images_enabled = $this->isVirtualImagesEnabled();
        $settings->virtual_images_detection = $this->getVirtualImagesDetection();
        return $settings;
    }

    /**
     * Read-only provenance for the Virtual Image URLs default (issue #233): what the Edge Cache Probe
     * found on this site, for the frontend to disclose under the toggle. The verdict reports the
     * **detection result**, not the toggle state — a manual save can override the value while the verdict
     * stays what was measured (the frontend derives its warning from the two together). With no verdict,
     * the three ways detection can be over or pending are distinguished, so the UI never claims
     * "hasn't completed yet" for a state that will never complete: an explicitly-set option means a save
     * ended probing before any verdict (`manual`), a spent attempt cap means the probe gave up
     * (`inconclusive`), and only a probe that can still run reports `undetermined`. Surfaced in the GET
     * response only — the save path neither accepts nor persists it.
     */
    public function getVirtualImagesDetection(): string
    {
        switch ($this->edgeCacheProbeStore->get_verdict()) {
            case EdgeCacheProbeStore::VERDICT_ABSORBED:
                return 'detected_on';
            case EdgeCacheProbeStore::VERDICT_UNABSORBED:
                return 'detected_off';
        }
        if (get_option(self::OPTION_VIRTUAL_IMAGES_ENABLED, false) !== false) {
            return 'manual';
        }
        if ($this->edgeCacheProbeStore->get_attempts() >= EdgeCacheProbe::MAX_ATTEMPTS) {
            return 'inconclusive';
        }
        return 'undetermined';
    }

    /**
     * The default Render Mode ('classic' | 'live') pre-selected in the create form for new
     * Page Sets. Global option; Live is only honoured for Extended users (enforced at creation).
     */
    public function getDefaultRenderMode(): string
    {
        return $this->sanitizeRenderMode(get_option(self::OPTION_DEFAULT_RENDER_MODE, 'classic'));
    }

    private function sanitizeRenderMode($value): string
    {
        return $value === 'live' ? 'live' : 'classic';
    }

    /**
     * Whether "Generate in background" is pre-selected across the create/update/re-upload/switch
     * flows. Global option, default off; Background Generation is Extended-only, so this is only
     * honoured for Extended users (limited plans always resolve to false, and the enqueue endpoints
     * enforce the tier regardless).
     */
    public function getDefaultBackgroundGeneration(): bool
    {
        return filter_var(get_option(self::OPTION_DEFAULT_BACKGROUND_GENERATION, '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the site-wide Virtual Image URLs setting is on (ADR 0015, default reversed to off by
     * ADR 0016). Global option, default off, no premium gate (the render path is free per ADR 0009).
     * The unset option reads as off so a site the Edge Cache Probe has not yet judged serves shared
     * source URLs; the probe writes the option once it has evidence. This is one AND-input in
     * {@see \LPagery\service\live_render\VirtualImageAvailability::site_wide_enabled()}; when off, Live
     * Mode pages render the Source URL Fallback shape site-wide.
     */
    public function isVirtualImagesEnabled(): bool
    {
        return filter_var(get_option(self::OPTION_VIRTUAL_IMAGES_ENABLED, '0'), FILTER_VALIDATE_BOOLEAN);
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
     * Checks if image partial match (LIKE fallback) is enabled
     */
    public function isImagePartialMatchEnabled($processId = null): bool
    {
        $userId = $this->getUserId($processId);
        $userSettings = $this->getUserSettings($userId);

        return filter_var($userSettings['image_partial_match'] ?? true, FILTER_VALIDATE_BOOLEAN);
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

        $result = $userSettings['custom_post_types'] ?? [];
        array_push($result, "page", "post");
        return $result;
    }

    /**
     * Retrieves the user ID
     */
    private function getUserId($processId = null): int
    {
        $userId = get_current_user_id();

        if (!$userId && $processId) {
            $process = $this->pageSetRepository->get_process_by_id($processId);
            if (!empty($process)) {
                $userId = $process->user_id;
            } else {
                $userId = 0;
            }
        }
        if(!$userId) {
            $user = get_user_by('id', $processId);
            if($user) {
                $userId = $user->ID;
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
                'image_partial_match' => true,
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

    /**
     * Checks if hide generated pages is enabled
     */
    public function isHideGeneratedPagesEnabled(): bool
    {
        if ($this->isLimitedPlan()) {
            return false;
        }
        return filter_var(get_option(self::OPTION_HIDE_GENERATED_PAGES, '0'), FILTER_VALIDATE_BOOLEAN);
    }
}
