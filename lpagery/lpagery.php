<?php

/*
Plugin Name: LPagery
Plugin URI: https://lpagery.io/
Description: Create hundreds or even thousands of landingpages for local businesses, services etc.
Version: 3.0.0
Author: LPagery
License: GPLv2 or later
*/
// Create a helper function for easy SDK access.
use LPagery\utils\Utils;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( function_exists( 'lpagery_fs' ) ) {
    lpagery_fs()->set_basename( false, __FILE__ );
} else {
    // DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE `function_exists` CALL ABOVE TO PROPERLY WORK.
    /** @phpstan-ignore booleanNot.alwaysTrue */
    if ( !function_exists( 'lpagery_fs' ) ) {
        function lpagery_fs() {
            global $lpagery_fs;
            if ( !isset( $lpagery_fs ) ) {
                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/freemius/start.php';
                $lpagery_fs = fs_dynamic_init( array(
                    'id'               => '9985',
                    'slug'             => 'lpagery',
                    'premium_slug'     => 'lpagery-pro',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_708ce9268236202bb1fd0aceb0be2',
                    'is_premium'       => false,
                    'premium_suffix'   => 'Pro',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'has_affiliation'  => 'customers',
                    'menu'             => array(
                        'slug'    => 'lpagery',
                        'contact' => false,
                        'support' => false,
                    ),
                    'is_live'          => true,
                    'is_org_compliant' => true,
                ) );
            }
            return $lpagery_fs;
        }

        // Init Freemius.
        lpagery_fs();
        // Signal that SDK was initiated.
        do_action( 'lpagery_fs_loaded' );
    }
    require __DIR__ . "/vendor/autoload.php";
    require __DIR__ . '/src/polyfills.php';
    $plugin_data = get_file_data( __FILE__, array(
        'Version' => 'Version',
    ) );
    $lpagery_version = $plugin_data['Version'];
    define( 'LPAGERY_VERSION', $lpagery_version );
    function lpagery_activate() {
        lpagery_root()->databaseMigrator()->migrate();
        // The Image Endpoint's rewrite rule (ADR 0013) needs a flush on activation so Virtual Image
        // URLs resolve without the user manually saving permalinks.
        lpagery_root()->imageEndpoint()->flush_on_activation();
        if ( !get_option( "lpagery_queue_create_post_secret" ) ) {
            add_option( "lpagery_queue_create_post_secret", Utils::generateRandomString( 32 ) );
        }
    }

    register_activation_hook( __FILE__, 'lpagery_activate' );
    \LPagery\io\hooks\AdminMenuHooks::register();
    include_once plugin_dir_path( __FILE__ ) . '/src/io/AjaxActions.php';
    lpagery_fs()->add_filter( 'permission_list', 'add_lpagery_permssions' );
    function add_lpagery_permssions(  $permissions  ) {
        $permissions['tracking'] = array(
            'id'         => 'tracking',
            'icon-class' => 'dashicons dashicons-cloud',
            'label'      => lpagery_fs()->get_text_inline( 'View User Behaviour', 'tracking' ),
            'desc'       => lpagery_fs()->get_text_inline( 'Allow tracking of user behaviour to improve the plugin', 'permissions-tracking' ),
            'tooltip'    => lpagery_fs()->get_text_inline( 'We do not track any personal or sensitive data. We just want to understand what our users do, to make the plugin better.', 'permissions-tracking' ),
            'priority'   => 35,
        );
        $permissions['error_monitoring'] = array(
            'id'         => 'error_monitoring',
            'icon-class' => 'dashicons dashicons-warning',
            'label'      => lpagery_fs()->get_text_inline( 'Error Monitoring', 'error_monitoring' ),
            'desc'       => lpagery_fs()->get_text_inline( 'Allow monitoring of errors to improve the plugin', 'permissions-error-monitoring' ),
            'tooltip'    => lpagery_fs()->get_text_inline( 'We do not track any personal or sensitive data. We just want to understand what errors occur, to make the plugin better.', 'permissions-error-monitoring' ),
            'priority'   => 36,
        );
        if ( lpagery_fs()->is_premium() ) {
            $permissions["intercom"] = array(
                'id'         => 'intercom',
                'icon-class' => 'dashicons dashicons-admin-comments',
                'label'      => lpagery_fs()->get_text_inline( 'Intercom', 'intercom' ),
                'desc'       => lpagery_fs()->get_text_inline( 'Allow Intercom to be shown in the plugin', 'permissions-intercom' ),
                'tooltip'    => lpagery_fs()->get_text_inline( 'We use Intercom to provide support and help you with the plugin.', 'permissions-intercom' ),
                'priority'   => 37,
            );
        }
        return $permissions;
    }

    function lpagery_info_log(  $message  ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $message );
        }
    }

    function lpagery_root() : \LPagery\CompositionRoot {
        global $lpagery_root;
        if ( !$lpagery_root instanceof \LPagery\CompositionRoot ) {
            $lpagery_root = new \LPagery\CompositionRoot();
        }
        return $lpagery_root;
    }

    new \LPagery\suite\SuiteRestApi();
    \LPagery\io\hooks\AdminAppHooks::register();
    \LPagery\io\hooks\AdminListTableHooks::register();
    \LPagery\io\hooks\LiveRenderHooks::register();
    \LPagery\io\hooks\ImageEndpointHooks::register();
    \LPagery\io\hooks\EndpointHealthHooks::register();
    \LPagery\io\hooks\BackgroundWorkerHooks::register();
    \LPagery\io\hooks\EdgeCacheProbeHooks::register();
    \LPagery\io\hooks\ShortcodeHooks::register();
    \LPagery\io\hooks\AttachmentIndexHooks::register();
    if ( !lpagery_fs()->is_plan_or_trial( 'extended' ) ) {
        wp_clear_scheduled_hook( "lpagery_sync_google_sheet" );
        wp_clear_scheduled_hook( "lpagery_queue_worker_cron_event" );
        wp_clear_scheduled_hook( "lpagery_trigger_cron_started_syncs" );
    }
    lpagery_fs()->add_filter( 'pricing_url', function () {
        return "https://lpagery.io/pricing/?utm_source=free_version&utm_medium=menu_item&utm_campaign=free";
    } );
    lpagery_fs()->add_filter( 'plugin_icon', function () {
        return dirname( __FILE__ ) . '/assets/lpagery.png';
    } );
}