<?php

namespace LPagery\controller;

use LPagery\data\LPageryDao;
use LPagery\service\onboarding\OnboardingService;
use LPagery\utils\MemoryUtils;
use LPagery\utils\Utils;

/**
 * Controller for handling utility operations
 */
class UtilityController
{
    private static $instance;
    private OnboardingService $onboardingService;
    private LPageryDao $lpageryDao;

    /**
     * UtilityController constructor.
     *
     * @param OnboardingService $onboardingService
     * @param LPageryDao $lpageryDao
     */
    public function __construct(OnboardingService $onboardingService, LPageryDao $lpageryDao)
    {
        $this->onboardingService = $onboardingService;
        $this->lpageryDao = $lpageryDao;
    }

    /**
     * Singleton pattern implementation
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self(
                OnboardingService::get_instance(),
                LPageryDao::get_instance()
            );
        }
        return self::$instance;
    }

    /**
     * Gets RAM usage
     *
     * @return array Memory usage information
     */
    public function getRAMUsage(): array
    {
        return MemoryUtils::lpagery_get_memory_usage();
    }

    /**
     * Gets users for settings
     *
     * @return array Array of users with ID and label
     */
    public function getUsersForSettings(): array
    {
        $users = get_users();
        return array_map(function ($user) {
            return [
                "id" => $user->ID,
                "label" => $user->display_name . " (" . $user->user_email . ")"
            ];
        }, $users);
    }

    /**
     * Gets users with processes
     * 
     * @return array Array of users with processes
     */
    public function getUsersWithProcesses(): array
    {
        return $this->lpageryDao->lpagery_get_users_with_processes();
    }

    /**
     * Creates an onboarding template page
     *
     * @return array Result of operation
     */
    public function createOnboardingTemplatePage(): array
    {
        $page_id = $this->onboardingService->createOnboardingTemplatePage();
        
        if (!$page_id) {
            return ["success" => false];
        }
        
        return [
            "success" => true,
            "page_id" => $page_id
        ];
    }

    /**
     * Gets Google Sheet scheduled data
     *
     * @return array Google Sheet scheduled data
     */
    public function getGoogleSheetScheduledData(): array
    {
        $event = wp_get_scheduled_event('lpagery_sync_google_sheet');
        $response = [];

        if ($event) {
            // Next sync timestamp
            $response['next_sync_timestamp'] = $event->timestamp;

            // Schedule interval (e.g., hourly, daily)
            $response['schedule_interval'] = $event->schedule;

            // Time until the next sync in human-readable format
            if ($event->timestamp >= time()) {
                $response['time_until_next_sync'] = Utils::lpagery_time_ago($event->timestamp);
            }

            // Memory usage information (limit in bytes)
            $memory_usage = MemoryUtils::lpagery_get_memory_usage();
            $response['memory_limit_bytes'] = $memory_usage['limit'];
            $response['pretty_memory_limit'] = $memory_usage['pretty_limit'];
        }

        return $response;
    }
} 