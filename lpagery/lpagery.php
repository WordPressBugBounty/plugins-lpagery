<?php

/*
Plugin Name: LPagery
Plugin URI: https://lpagery.io/
Description: Create hundreds or even thousands of landingpages for local businesses, services etc.
Version: 2.4.12
Author: LPagery
License: GPLv2 or later
*/
// Create a helper function for easy SDK access.
use Kucrut\Vite;
use LPagery\data\LPageryDao;
use LPagery\factories\GoogleSheetSyncProcessHandlerFactory;
use LPagery\io\Mapper;
use LPagery\model\ProcessSheetSyncParams;
use LPagery\service\image_lookup\AttachmentBasenameService;
use LPagery\service\InstallationDateHandler;
use LPagery\service\settings\SettingsController;
use LPagery\service\sheet_sync\GoogleSheetQueueWorkerFactory;
use LPagery\service\sheet_sync\GoogleSheetSyncControllerFactory;
use LPagery\service\TrackingPermissionService;
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
                    'id'              => '9985',
                    'slug'            => 'lpagery',
                    'premium_slug'    => 'lpagery-pro',
                    'type'            => 'plugin',
                    'public_key'      => 'pk_708ce9268236202bb1fd0aceb0be2',
                    'is_premium'      => false,
                    'premium_suffix'  => 'Pro',
                    'has_addons'      => false,
                    'has_paid_plans'  => true,
                    'has_affiliation' => 'customers',
                    'menu'            => array(
                        'slug'    => 'lpagery',
                        'contact' => false,
                        'support' => false,
                    ),
                    'is_live'         => true,
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
    $plugin_data = get_file_data( __FILE__, array(
        'Version' => 'Version',
    ) );
    $lpagery_version = $plugin_data['Version'];
    define( 'LPAGERY_VERSION', $lpagery_version );
    function lpagery_activate() {
        LPageryDao::get_instance()->init_db();
        if ( !get_option( "lpagery_queue_create_post_secret" ) ) {
            add_option( "lpagery_queue_create_post_secret", Utils::generateRandomString( 32 ) );
        }
    }

    register_activation_hook( __FILE__, 'lpagery_activate' );
    add_action( 'admin_menu', 'lpagery_setup_menu' );
    function lpagery_setup_menu() {
        $icon_base64 = 'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPCEtLSBHZW5lcmF0b3I6IEFkb2JlIElsbHVzdHJhdG9yIDI2LjIuMSwgU1ZHIEV4cG9ydCBQbHVnLUluIC4gU1ZHIFZlcnNpb246IDYuMDAgQnVpbGQgMCkgIC0tPgo8c3ZnIHZlcnNpb249IjEuMSIgaWQ9IkViZW5lXzEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4IgoJIHZpZXdCb3g9IjAgMCA1MjcuMTYgNjc0LjQ1IiBzdHlsZT0iZW5hYmxlLWJhY2tncm91bmQ6bmV3IDAgMCA1MjcuMTYgNjc0LjQ1OyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+CjxzdHlsZSB0eXBlPSJ0ZXh0L2NzcyI+Cgkuc3Qwe2ZpbGw6I0ZGRkZGRjt9Cgkuc3Qxe2ZpbGw6bm9uZTtzdHJva2U6I0ZGRkZGRjtzdHJva2Utd2lkdGg6MztzdHJva2UtbWl0ZXJsaW1pdDoxMDt9Cjwvc3R5bGU+CjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0yNTAuNDUsMzQ3LjYySDExMi4zOWMwLTAuMDEsMC0wLjAyLDAtMC4wMmwtMC4wMSwwLjAxbDAtMTg0LjQ5YzAtMzEuMDMtMjUuMTUtNTYuMTgtNTYuMTgtNTYuMTgKCWMwLDAsMCwwLTAuMDEsMEMyNS4xNiwxMDYuOTMsMCwxMzIuMDksMCwxNjMuMTFsMCwyNDAuNjJjMCwyOS44OSwyMi4wOCw1NC4yOSw1MS40OSw1Ni4wNGMxLjU4LDAuMTMsMy4xNiwwLjIyLDQuNzcsMC4yMgoJbDg5LjkxLTAuMTRsMzQuMzktMC4wMmwwLjAzLTAuMDNsMi4wMSwwTDI1MC40NSwzNDcuNjJ6Ii8+CjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik01MDMuODcsMjg2Ljc1Yy0xLjMyLTAuOTYtMi42OC0xLjg5LTQuMS0yLjc1TDM4OC43LDIxNi43OGwtMC4wMSwwbDAsMGwtMTAuNTUtNi4zOWwtMzIuMDItMTcuOTlsLTI5LjU1LDQ4LjMzCglsLTI4LjM5LDQ2LjMxbDEwNS4zMSw2My45YzAsMC4wMS0wLjAxLDAuMDMtMC4wMSwwLjAzbDAuMDIsMGwtOTUuNzIsMTU3LjcyYy0xNi4wOSwyNi41My03LjY0LDYxLjA5LDE4Ljg5LDc3LjE4CgljMjYuNTMsMTYuMSw2MS4wOSw3LjY0LDc3LjE4LTE4Ljg5bDEyNC44My0yMDUuNzFDNTM0LjE2LDMzNS43Nyw1MjcuOTgsMzAzLjUyLDUwMy44NywyODYuNzV6Ii8+CjxsaW5lIGNsYXNzPSJzdDEiIHgxPSI1Ni45NyIgeTE9IjY2NS4yNCIgeDI9IjQ2My43OCIgeTI9IjAiLz4KPC9zdmc+Cg==';
        $icon_data_uri = 'data:image/svg+xml;base64,' . $icon_base64;
        // Get current view parameter
        $current_view = ( isset( $_GET['view'] ) ? $_GET['view'] : '' );
        add_menu_page(
            'LPagery',
            'LPagery',
            'manage_options',
            'lpagery',
            'bootstrap',
            $icon_data_uri
        );
        // Add submenu items for Pro version
        add_submenu_page(
            'lpagery',
            'Create Pages',
            'Create Pages',
            'manage_options',
            'lpagery&view=create',
            'bootstrap'
        );
        add_submenu_page(
            'lpagery',
            'Update Pages',
            'Update Pages',
            'manage_options',
            'lpagery&view=update',
            'bootstrap'
        );
        add_submenu_page(
            'lpagery',
            'Manage Pages',
            'Manage Pages',
            'manage_options',
            'lpagery&view=manage',
            'bootstrap'
        );
        add_submenu_page(
            'lpagery',
            'Settings',
            'Settings',
            'manage_options',
            'lpagery&view=settings',
            'bootstrap'
        );
        global $submenu;
        if ( isset( $submenu['lpagery'] ) ) {
            // Remove the first item which is the duplicate
            unset($submenu['lpagery'][0]);
        }
        // Add filter to modify current menu parent
        add_filter( 'parent_file', function ( $parent_file ) use($current_view) {
            global $submenu_file;
            if ( isset( $_GET['page'] ) && $_GET['page'] === 'lpagery' ) {
                $submenu_file = 'lpagery&view=' . $current_view;
            }
            return $parent_file;
        } );
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'lpagery' && isset( $_GET['authorize'] ) && $_GET['authorize'] === 'true' ) {
            if ( !is_user_logged_in() ) {
                // Get the current URL
                $current_url = (( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? "https" : "http" )) . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
                // Create the login URL with proper redirect
                $login_url = wp_login_url( $current_url );
                // Redirect to login
                wp_redirect( $login_url );
                exit;
            }
            // Generate a unique code for this authorization
            $code = wp_generate_password( 32, false );
            $user_id = get_current_user_id();
            // Store the code with user ID and additional data in transients
            $nonce = wp_create_nonce( 'suite_oauth_' . $code );
            set_transient( 'suite_oauth_code_' . $code, [
                'user_id'               => $user_id,
                'timestamp'             => time(),
                'app_user_mail_address' => sanitize_email( $_GET["app_user_mail_address"] ),
                'nonce'                 => $nonce,
            ], 300 );
            $suite_origin = 'https://app.lpagery.io';
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>LPagery Authorization</title>
            </head>
            <body>
                <script>
                    (function() {
                        const code = '<?php 
            echo esc_js( $code );
            ?>';
                        const nonce = '<?php 
            echo esc_js( $nonce );
            ?>';
                        const user_id = '<?php 
            echo esc_js( strval( $user_id ) );
            ?>';
                        if (window.opener) {
                            window.opener.postMessage({
                                type: 'wordpress_auth',
                                nonce:nonce,
                                user_id:user_id,
                                code:code
                            }, '<?php 
            echo esc_js( $suite_origin );
            ?>');

                        }
                    })();
                </script>
                <p>Authorization completed. You can close this window.</p>
            </body>
            </html>
            <?php 
            exit;
        }
        add_action( 'admin_footer', function () {
            ?>
            <script>
            window.addEventListener('lpageryHeaderChange', function(e) {
                // Find and update the active menu state
                jQuery('#adminmenu .wp-submenu li').removeClass('current');
                jQuery('#adminmenu .wp-submenu a').removeClass('current');

                // Find the matching menu item and highlight both the link and its parent li
                var $menuLink = jQuery('#adminmenu .wp-submenu a[href*="lpagery&view=' + e.detail.header + '"]');
                $menuLink.addClass('current');
                $menuLink.parent('li').addClass('current');

                // Also update the first menu item if we're on the main view
                if (!e.detail.header || e.detail.header === '') {
                    jQuery('#adminmenu .wp-submenu li:first-child').addClass('current');
                    jQuery('#adminmenu .wp-submenu li:first-child a').addClass('current');
                }
            });
            </script>
            <?php 
        } );
        if ( !TrackingPermissionService::get_instance( InstallationDateHandler::get_instance() )->getPermissions()->getIntercom() ) {
            add_submenu_page(
                'lpagery',
                // Parent slug
                'Contact Us',
                // Page title
                'Contact Us',
                // Menu title
                'manage_options',
                // Capability
                'lpagery_contact',
                // Menu slug
                function () {
                    // Callback function to handle redirect
                    wp_redirect( 'https://lpagery.io/contact/' );
                    exit;
                }
            );
            // Add JavaScript to handle the redirect
            add_action( 'admin_footer', function () {
                ?>
                <script>
                jQuery(document).ready(function($) {
                    // Directly modify the menu item when the page loads
                    $('a[href*="admin.php?page=lpagery_contact"]').attr('href', 'https://lpagery.io/contact/').attr('target', '_blank');

                    // Prevent the default navigation and redirect if someone clicks before JS runs
                    $(document).on('click', 'a[href*="admin.php?page=lpagery_contact"]', function(e) {
                        e.preventDefault();
                        window.open('https://lpagery.io/contact/', '_blank');
                    });
                });
                </script>
                <?php 
            } );
        }
    }

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

    new \LPagery\suite\SuiteRestApi();
    add_action( 'admin_enqueue_scripts', function ( $page ) : void {
        if ( $page !== 'toplevel_page_lpagery' ) {
            return;
        }
        Vite\enqueue_asset( __DIR__ . '/frontend/dist', 'src/index.tsx', [
            'handle'       => 'lpagery_scripts',
            'dependencies' => ['react', 'react-dom'],
            'css-media'    => 'all',
            'css-only'     => false,
            'in-footer'    => true,
        ] );
        global $wpdb;
        $table_name_tokens = $wpdb->prefix . 'lpagery_app_tokens';
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $app_connected = $wpdb->get_var( "SELECT EXISTS (SELECT * FROM {$table_name_tokens})" );
        $process_from_app_exists = $wpdb->get_var( "SELECT EXISTS (SELECT * FROM {$table_name_process} WHERE managing_system = 'app')" );
        $installationDateHandler = InstallationDateHandler::get_instance();
        $lpagery_scripts_object = array(
            'is_free_plan'                 => (bool) lpagery_fs()->is_free_plan(),
            'is_premium_code'              => (bool) lpagery_fs()->is_premium(),
            'has_features_enabled_license' => (bool) lpagery_fs()->has_features_enabled_license(),
            'is_extended_plan'             => (bool) lpagery_fs()->is_plan_or_trial__premium_only( "extended" ),
            'is_standard_plan'             => (bool) lpagery_fs()->is_plan_or_trial__premium_only( "standard" ),
            'ajax_url'                     => admin_url( 'admin-ajax.php' ),
            'nonce'                        => wp_create_nonce( "lpagery_ajax" ),
            'plugin_dir'                   => plugin_dir_url( dirname( __FILE__ ) ),
            'upload_dir'                   => wp_upload_dir(),
            'tracking_permissions'         => TrackingPermissionService::get_instance( $installationDateHandler )->getPermissions(),
            'allowed_placeholders'         => $installationDateHandler->get_placeholder_counts(),
            'max_pages_per_run'            => $installationDateHandler->get_max_pages_per_run(),
            'version'                      => LPAGERY_VERSION,
            'username'                     => wp_get_current_user()->display_name,
            'app_connected'                => (bool) $app_connected,
            'process_from_app_exists'      => (bool) $process_from_app_exists,
            'wpml_installed'               => (bool) defined( 'ICL_SITEPRESS_VERSION' ),
        );
        // Encode the data as JSON and output it inline
        wp_add_inline_script( 'lpagery_scripts', 'const lpagery_scripts_object = ' . wp_json_encode( $lpagery_scripts_object ) . ';', 'before' );
    } );
    function bootstrap() : void {
        printf( '<div id="lpagery-container" class="lpagery-tailwind" ></div>' );
    }

    add_action( 'admin_init', 'lpagery_admin_init' );
    function lpagery_admin_init() {
        LPageryDao::get_instance()->init_db();
    }

    function suppress_all_admin_notices_for_lpagery() {
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'toplevel_page_lpagery' ) !== false ) {
            // Replace 'LPagery' with your plugin's screen ID if different
            remove_all_actions( 'admin_notices' );
        }
    }

    add_action( 'admin_head', 'suppress_all_admin_notices_for_lpagery' );
    add_filter(
        'posts_where',
        'lpagery_source_filter',
        10,
        2
    );
    function lpagery_source_filter(  $where, $query  ) {
        global $wpdb;
        // Only apply filter on admin post listing pages
        if ( !is_admin() || !$query->is_main_query() ) {
            return $where;
        }
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        if ( !isset( $_GET['lpagery_process'] ) && !isset( $_GET['lpagery_template'] ) ) {
            return $where;
        }
        if ( isset( $_GET['lpagery_template'] ) ) {
            $lpagery_template_id = $_GET['lpagery_template'];
            if ( $lpagery_template_id != '' ) {
                $lpagery_template_id = intval( $lpagery_template_id );
                $where .= $wpdb->prepare( " AND EXISTS (\n                    SELECT pp.id \n                    FROM {$table_name_process_post} pp\n                    WHERE pp.template_id = %d \n                    AND pp.post_id = {$wpdb->posts}.id\n                )", $lpagery_template_id );
            }
        } else {
            $lpagery_process_id = $_GET['lpagery_process'];
            if ( $lpagery_process_id != '' ) {
                $lpagery_process_id = intval( $lpagery_process_id );
                $where .= $wpdb->prepare( " AND EXISTS (\n                    SELECT pp.id\n                    FROM {$table_name_process_post} pp\n                    WHERE pp.lpagery_process_id = %d\n                    AND pp.post_id = {$wpdb->posts}.id\n                )", $lpagery_process_id );
            }
        }
        return $where;
    }

    add_action( 'restrict_manage_posts', 'lpagery_customized_filters' );
    function lpagery_customized_filters() {
        ?>
        <input id="lpagery_reset_filter" class="button" type="button" value="Reset LPagery Filter"
               style="display: none">
        <?php 
    }

    add_action( 'admin_footer', 'lpagery_add_filter_text_process' );
    add_action( 'admin_footer', 'lpagery_add_filter_text_template_post' );
    function lpagery_add_filter_text_process() {
        if ( !isset( $_GET['lpagery_process'] ) ) {
            return;
        }
        $lpagery_process_id = $_GET['lpagery_process'];
        $process = LPageryDao::get_instance()->get_instance()->lpagery_get_process_by_id( $lpagery_process_id );
        if ( empty( $process ) ) {
            return;
        }
        $mapper = Mapper::get_instance();
        $process = $mapper->lpagery_map_process( $process );
        $post_id = $process["post_id"];
        $purpose = $process["display_purpose"];
        $post_title = get_post( $post_id )->post_title;
        $permalink = get_permalink( $post_id );
        if ( $post_title ) {
            ?>
            <script>
                jQuery(function ($) {
                    let test = $('<span><?php 
            echo $purpose;
            ?> with Template: <a href=<?php 
            echo $permalink;
            ?>> <?php 
            echo $post_title;
            ?><a/></span')
                    $('<div style="margin-bottom:5px;"></div>').append(test).insertAfter('#wpbody-content .wrap h2:eq(0)');
                });
            </script><?php 
        }
    }

    function lpagery_add_filter_text_template_post() {
        if ( !isset( $_GET['lpagery_template'] ) ) {
            return;
        }
        $lpagery_template_id = $_GET['lpagery_template'];
        $post = get_post( $lpagery_template_id );
        $post_title = $post->post_title;
        $permalink = get_permalink( $post );
        if ( $post_title ) {
            ?>
            <script>
                jQuery(function ($) {
                    let test = $('<span>Show all created pages with Template: <a href=<?php 
            echo $permalink;
            ?>> <?php 
            echo $post_title;
            ?><a/></span')
                    $('<div style="margin-bottom:5px;"></div>').append(test).insertAfter('#wpbody-content .wrap h2:eq(0)');
                });
            </script><?php 
        }
    }

    function lpagery_filter_add_export_row_action(  $actions, WP_Post $post  ) {
        $post_id = $post->ID;
        $process_id_result = LPageryDao::get_instance()->lpagery_get_process_id_by_template( $post_id );
        if ( $process_id_result ) {
            $nonce = wp_create_nonce( "lpagery_ajax" );
            $actions['lpagery_export_page'] = sprintf( '<a href="%1$s" target="_blank">%2$s</a>', get_admin_url( null, 'admin-ajax.php' ) . '?action=lpagery_download_post_json&process_id=' . $process_id_result["process_id"] . '&_ajax_nonce=' . $nonce, __( 'LPagery: Export Template Page', 'lpagery' ) );
        }
        return $actions;
    }

    add_filter(
        'post_row_actions',
        'lpagery_filter_add_export_row_action',
        2,
        2
    );
    add_filter(
        'page_row_actions',
        'lpagery_filter_add_export_row_action',
        2,
        2
    );
    if ( !function_exists( 'str_contains' ) ) {
        function str_contains(  $haystack, $needle  ) {
            return '' === $needle || false !== strpos( $haystack, $needle );
        }

    }
    if ( !function_exists( 'str_starts_with' ) ) {
        function str_starts_with(  $haystack, $needle  ) {
            if ( '' === $needle ) {
                return true;
            }
            return 0 === strpos( $haystack, $needle );
        }

    }
    if ( !function_exists( 'str_ends_with' ) ) {
        function str_ends_with(  $haystack, $needle  ) {
            if ( '' === $haystack && '' !== $needle ) {
                return false;
            }
            $len = strlen( $needle );
            return 0 === substr_compare(
                $haystack,
                $needle,
                -$len,
                $len
            );
        }

    }
    add_shortcode( 'lpagery_urls', 'add_lpagery_urls_shortcode' );
    function add_lpagery_urls_shortcode(  $atts  ) {
        if ( isset( $atts["id"] ) ) {
            $post_ids = LPageryDao::get_instance()->lpagery_get_posts_by_process( $atts["id"] );
            if ( !empty( $post_ids ) ) {
                $list_items = '';
                foreach ( $post_ids as $record ) {
                    $post_id = $record->id;
                    $post_title = get_the_title( $post_id );
                    $post_permalink = get_permalink( $post_id );
                    $list_items .= "<li class='lpagery_created_page_item'><a class='lpagery_created_page_anchor' href='{$post_permalink}'>{$post_title}</a></li>";
                }
                return "<ul class='lpagery_created_page_list'>{$list_items}</ul>";
            }
        }
        return null;
    }

    add_shortcode( 'lpagery_link', 'add_lpagery_link_shortcode' );
    function add_lpagery_link_shortcode(  $atts  ) {
        $post_id = get_the_ID();
        $plan_post_created = get_post_meta( $post_id, '_lpagery_plan', true );
        if ( !($plan_post_created === 'PRO' || lpagery_fs()->is_plan_or_trial( 'EXTENDED' )) ) {
            return null;
        }
        $slug = $atts['slug'] ?? null;
        $position = $atts['position'] ?? null;
        $circle = filter_var( $atts['circle'], FILTER_VALIDATE_BOOLEAN );
        $title = $atts['title'] ?? null;
        $target = $atts['target'] ?? '_self';
        $allowed_targets = [
            '_blank',
            '_self',
            '_parent',
            '_top'
        ];
        if ( !in_array( $target, $allowed_targets ) ) {
            $target = '_self';
            // Default to _self if target is not valid
        }
        $found_post = null;
        if ( $slug ) {
            $slug = sanitize_title( $slug );
            $found_post = LPageryDao::get_instance()->lpagery_get_post_by_slug_for_link( $slug );
        } elseif ( $position ) {
            $position = sanitize_text_field( $position );
            $allowed_positions = [
                'FIRST',
                'LAST',
                'NEXT',
                'PREV'
            ];
            if ( !in_array( $position, $allowed_positions ) ) {
                return null;
            }
            $found_post = LPageryDao::get_instance()->lpagery_get_post_at_position_in_process( $post_id, $position, $circle );
        }
        if ( $found_post ) {
            $title = ( empty( $title ) ? $found_post['post_title'] : $title );
            return '<a class="lpagery_link_anchor" href="' . esc_url( get_permalink( $found_post['id'] ) ) . '" target="' . esc_attr( $target ) . '">' . esc_html( $title ) . '</a>';
        }
        return null;
    }

    function lpagery_add_media_fields(  $form_fields, $post  ) {
        $settingsController = SettingsController::get_instance();
        if ( $settingsController->isImageProcessingEnabled() ) {
            $form_fields['lpagery_replace_filename'] = array(
                'label' => '<img width="25px" height ="25px" src="' . plugin_dir_url( dirname( __FILE__ ) ) . "/" . plugin_basename( dirname( __FILE__ ) ) . '/assets/lpagery.png"/>Download Filename',
                'input' => 'text',
                'value' => get_post_meta( $post->ID, '_lpagery_replace_filename', true ),
                'helps' => 'The name for LPagery to be taken for downloading images when using this image as an placeholder. The ending will be populated automatically. Please add placeholders from the input file here (e.g. "my-image-in-{city}")',
            );
            $form_fields['lpagery_update_metadata'] = array(
                'label' => '<img width="25px" height="25px" src="' . plugin_dir_url( dirname( __FILE__ ) ) . "/" . plugin_basename( dirname( __FILE__ ) ) . '/assets/lpagery.png"/>Update Metadata',
                'input' => 'html',
                'html'  => '<input type="checkbox" name="attachments[' . $post->ID . '][lpagery_update_metadata]" value="1" ' . checked( get_post_meta( $post->ID, '_lpagery_update_metadata', true ), '1', false ) . ' />',
                'helps' => 'Check this box to update the image metadata of existing replacement images when processing this image',
            );
        }
        return $form_fields;
    }

    add_filter(
        'attachment_fields_to_edit',
        'lpagery_add_media_fields',
        10,
        2
    );
    // Keep basename lookup table in sync for fast attachment searches
    // Note: add_attachment fires BEFORE _wp_attached_file metadata is set, so we use hooks that fire after
    // Hook into attachment metadata generation (fires after _wp_attached_file is set for new uploads)
    add_filter(
        'wp_generate_attachment_metadata',
        'lpagery_on_attachment_metadata_generated',
        10,
        2
    );
    function lpagery_on_attachment_metadata_generated(  $metadata, $attachment_id  ) {
        $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $file ) {
            AttachmentBasenameService::get_instance()->insert( $attachment_id, $file );
        }
        return $metadata;
    }

    // Hook into attachment metadata updates
    add_filter(
        'wp_update_attachment_metadata',
        'lpagery_on_attachment_metadata_updated',
        10,
        2
    );
    function lpagery_on_attachment_metadata_updated(  $metadata, $attachment_id  ) {
        $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $file ) {
            AttachmentBasenameService::get_instance()->insert( $attachment_id, $file );
        }
        return $metadata;
    }

    // Hook into post meta addition (catches _wp_attached_file being set)
    add_action(
        'added_post_meta',
        'lpagery_on_attachment_file_meta_added',
        10,
        4
    );
    function lpagery_on_attachment_file_meta_added(
        $meta_id,
        $post_id,
        $meta_key,
        $meta_value
    ) {
        if ( $meta_key === '_wp_attached_file' && $meta_value ) {
            AttachmentBasenameService::get_instance()->insert( $post_id, $meta_value );
        }
    }

    // Hook into post meta updates (catches _wp_attached_file being updated)
    add_action(
        'updated_post_meta',
        'lpagery_on_attachment_file_meta_updated',
        10,
        4
    );
    function lpagery_on_attachment_file_meta_updated(
        $meta_id,
        $post_id,
        $meta_key,
        $meta_value
    ) {
        if ( $meta_key === '_wp_attached_file' && $meta_value ) {
            AttachmentBasenameService::get_instance()->insert( $post_id, $meta_value );
        }
    }

    // Hook into attachment edits
    add_action( 'edit_attachment', 'lpagery_on_edit_attachment' );
    function lpagery_on_edit_attachment(  $attachment_id  ) {
        $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $file ) {
            AttachmentBasenameService::get_instance()->insert( $attachment_id, $file );
        }
    }

    // Hook into attachment updates (fires when attachment post is updated)
    add_action(
        'attachment_updated',
        'lpagery_on_attachment_updated',
        10,
        3
    );
    function lpagery_on_attachment_updated(  $post_id, $post_after, $post_before  ) {
        $file = get_post_meta( $post_id, '_wp_attached_file', true );
        if ( $file ) {
            AttachmentBasenameService::get_instance()->insert( $post_id, $file );
        }
    }

    // Hook into REST API attachment creation/updates
    add_action(
        'rest_after_insert_attachment',
        'lpagery_on_rest_attachment_insert',
        10,
        3
    );
    function lpagery_on_rest_attachment_insert(  $attachment, $request, $creating  ) {
        $file = get_post_meta( $attachment->ID, '_wp_attached_file', true );
        if ( $file ) {
            AttachmentBasenameService::get_instance()->insert( $attachment->ID, $file );
        }
    }

    // Clean up basename lookup table when attachment is deleted
    add_action( 'delete_attachment', 'lpagery_delete_attachment_basename' );
    function lpagery_delete_attachment_basename(  $attachment_id  ) {
        AttachmentBasenameService::get_instance()->delete( $attachment_id );
    }

    // Daily cron job to backfill the attachment basename lookup table
    add_action( 'lpagery_backfill_attachment_basename', 'lpagery_backfill_attachment_basename' );
    function lpagery_backfill_attachment_basename() {
        AttachmentBasenameService::get_instance()->backfill();
    }

    // Schedule the daily backfill cron if not already scheduled
    if ( !wp_next_scheduled( 'lpagery_backfill_attachment_basename' ) ) {
        wp_schedule_event( time(), 'daily', 'lpagery_backfill_attachment_basename' );
    }
    function lpagery_save_replace_filename_field(  $post, $attachment  ) {
        if ( isset( $attachment['lpagery_replace_filename'] ) ) {
            // Update or add the custom field value
            update_post_meta( $post['ID'], '_lpagery_replace_filename', $attachment['lpagery_replace_filename'] );
        }
        if ( isset( $attachment['lpagery_update_metadata'] ) ) {
            update_post_meta( $post['ID'], '_lpagery_update_metadata', '1' );
        } else {
            update_post_meta( $post['ID'], '_lpagery_update_metadata', '0' );
        }
        return $post;
    }

    add_filter(
        'attachment_fields_to_save',
        'lpagery_save_replace_filename_field',
        10,
        2
    );
    if ( !lpagery_fs()->is_plan_or_trial( 'extended' ) ) {
        wp_clear_scheduled_hook( "lpagery_sync_google_sheet" );
        wp_clear_scheduled_hook( "lpagery_queue_worker_cron_event" );
        wp_clear_scheduled_hook( "lpagery_trigger_cron_started_syncs" );
    }
    add_action(
        'save_post',
        'lpagery_catch_manual_post_update',
        10,
        3
    );
    function lpagery_catch_manual_post_update(  $post_id, $post, $update  ) {
        $current_user_id = get_current_user_id();
        if ( !$current_user_id ) {
            return;
        }
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }
        if ( defined( 'DOING_LPAGERY_CREATION' ) && DOING_LPAGERY_CREATION ) {
            return;
        }
        if ( !$_POST ) {
            return;
        }
        if ( isset( $_POST["action"] ) && str_starts_with( $_POST["action"], "wpil_" ) ) {
            return;
        }
        if ( !$update ) {
            return;
        }
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->query( $wpdb->prepare( "UPDATE {$table_name_process_post} SET page_manually_updated_at = %s WHERE post_id = %d", current_time( 'mysql' ), $post_id ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$table_name_process_post} SET page_manually_updated_by = %s WHERE post_id = %d", $current_user_id, $post_id ) );
    }

    lpagery_fs()->add_filter( 'pricing_url', function () {
        return "https://lpagery.io/pricing/?utm_source=free_version&utm_medium=menu_item&utm_campaign=free";
    } );
    lpagery_fs()->add_filter( 'plugin_icon', function () {
        return dirname( __FILE__ ) . '/assets/lpagery.png';
    } );
    // Add this new function to handle the menu click tracking
    add_action( 'admin_footer', 'lpagery_add_menu_tracking' );
    function lpagery_add_menu_tracking() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Target main menu item and specific submenu items
            $('a[href="admin.php?page=lpagery"], ' +
            'a[href*="lpagery&view=create"], ' +
            'a[href*="lpagery&view=update"], ' +
            'a[href*="lpagery&view=manage"], ' +
            'a[href*="lpagery&view=settings"]').on('click', function() {
                if (!localStorage.getItem('lpagery_intro_showed_free')) {
                    localStorage.setItem('lpagery_intro_showed_free', 'true');
                }
            });
        });
        </script>
        <?php 
    }

}