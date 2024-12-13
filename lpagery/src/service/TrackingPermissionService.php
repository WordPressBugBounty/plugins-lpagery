<?php

namespace LPagery\service;

use LPagery\model\TrackingPermissions;
use Throwable;

class TrackingPermissionService
{
    private static ?TrackingPermissionService $instance = null;
    private InstallationDateHandler $installationDateHandler;

    public static function get_instance(InstallationDateHandler $installationDateHandler): TrackingPermissionService
    {
        if (null === self::$instance) {
            self::$instance = new self($installationDateHandler);
        }
        return self::$instance;
    }

    private function __construct( InstallationDateHandler $installationDateHandler) {
        $this->installationDateHandler = $installationDateHandler;
    }

    public function getPermissions(): TrackingPermissions
    {
        $installedAfterThreshold = $this->installationDateHandler->initial_tracking_allowed();

        if (lpagery_fs()->is_free_plan()) {
            $tracking_allowed = lpagery_fs()->is_tracking_allowed() && $installedAfterThreshold;
            return new TrackingPermissions($tracking_allowed, $tracking_allowed, false);
        }

        $user_permissions = get_user_option("lpagery_tracking_permissions");
        if (!$user_permissions) {
            $tracking_allowed = lpagery_fs()->is_tracking_allowed() && $installedAfterThreshold;
            return new TrackingPermissions($tracking_allowed, $tracking_allowed, $tracking_allowed);
        }
        $user_permissions = maybe_unserialize($user_permissions);
        $sentry = $user_permissions['sentry'] ?? false;
        $posthog = $user_permissions['posthog'] ?? false;
        $intercom = $user_permissions['intercom'] ?? false;
        return new TrackingPermissions($sentry, $posthog, $intercom);
    }

    public function savePermissions(TrackingPermissions $permissions): void
    {
        $user_permissions = [
            'sentry' => $permissions->getSentry(),
            'posthog' => $permissions->getPosthog(),
            'intercom' => $permissions->getIntercom(),
        ];
        update_user_option(get_current_user_id(), 'lpagery_tracking_permissions', $user_permissions);
    }
}