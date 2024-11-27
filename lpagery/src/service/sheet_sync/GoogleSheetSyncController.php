<?php

namespace LPagery\service\sheet_sync;

use LPagery\service\settings\SettingsController;
use LPagery\data\LPageryDao;
use Throwable;
if ( !defined( 'ABSPATH' ) ) {
    return;
}
if ( !defined( 'TEST_RUNNING' ) ) {
    include_once plugin_dir_path( __FILE__ ) . '/../../utils/IncludeWordpressFiles.php';
}
class GoogleSheetSyncController {
    private static ?GoogleSheetSyncController $instance = null;

    private GoogleSheetSyncProcessHandler $googleSheetSyncProcessHandler;

    private LPageryDao $lpageryDao;

    private SettingsController $settingsController;

    public function __construct( GoogleSheetSyncProcessHandler $googleSheetSyncProcessHandler, LPageryDao $lpageryDao, SettingsController $settingsController ) {
        $this->googleSheetSyncProcessHandler = $googleSheetSyncProcessHandler;
        $this->lpageryDao = $lpageryDao;
        $this->settingsController = $settingsController;
    }

    public static function get_instance( GoogleSheetSyncProcessHandler $googleSheetSyncProcessHandler, LPageryDao $lpageryDao, SettingsController $settingsController ) : GoogleSheetSyncController {
        if ( null === self::$instance ) {
            self::$instance = new self($googleSheetSyncProcessHandler, $lpageryDao, $settingsController);
        }
        return self::$instance;
    }

    public function lpagery_sync_google_sheets() {
        return;
        $sheet_sync_type = $this->settingsController->lpagery_get_sheet_sync_type();
        if ( $sheet_sync_type == 'off' ) {
            error_log( 'Do not sync google sheets as it is turned off' );
            return;
        }
        if ( get_transient( "lpagery_sync_running" ) ) {
            return;
        }
        set_transient( "lpagery_sync_running", true, 600 );
        $startTime = time();
        $was_suspended = wp_suspend_cache_addition();
        try {
            if ( $sheet_sync_type == 'single_process' ) {
                wp_suspend_cache_addition( true );
            }
            set_time_limit( 30 );
            wp_raise_memory_limit( "cron" );
            delete_option( "lpagery_sheet_sync_ram_usage" );
            $processes = $this->lpageryDao->lpagery_get_processes_with_google_sheet_sync();
            foreach ( $processes as $process ) {
                $this->lpageryDao->lpagery_update_process_sync_status( $process["id"], "CRON_STARTED" );
            }
            foreach ( $processes as $process ) {
                set_time_limit( 120 );
                $this->googleSheetSyncProcessHandler->handleProcess( $process, $sheet_sync_type );
            }
            $endTime = time();
            delete_option( "lpagery_sync_duration" );
            add_option( "lpagery_sync_duration", $endTime - $startTime );
            delete_option( "lpagery_last_sync_finished" );
            add_option( "lpagery_last_sync_finished", time() );
        } catch ( Throwable $e ) {
            error_log( $e->__toString() );
            delete_option( "lpagery_last_sync_error" );
            add_option( "lpagery_last_sync_error", $e->__toString() );
        } finally {
            if ( $sheet_sync_type == 'single_process' ) {
                wp_suspend_cache_addition( $was_suspended );
            }
            delete_transient( "lpagery_sync_running" );
        }
    }

}
