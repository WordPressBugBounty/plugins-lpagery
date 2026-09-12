<?php

namespace LPagery\io\hooks;

use LPagery\suite\SuiteOAuthAuthorizeHandler;
class AdminMenuHooks {
    public static function register() : void {
        add_action( 'admin_menu', [self::class, 'setup_menu'] );
        add_action( 'admin_head', [self::class, 'suppress_all_admin_notices'] );
    }

    public static function setup_menu() {
        $icon_base64 = 'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPCEtLSBHZW5lcmF0b3I6IEFkb2JlIElsbHVzdHJhdG9yIDI2LjIuMSwgU1ZHIEV4cG9ydCBQbHVnLUluIC4gU1ZHIFZlcnNpb246IDYuMDAgQnVpbGQgMCkgIC0tPgo8c3ZnIHZlcnNpb249IjEuMSIgaWQ9IkViZW5lXzEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4IgoJIHZpZXdCb3g9IjAgMCA1MjcuMTYgNjc0LjQ1IiBzdHlsZT0iZW5hYmxlLWJhY2tncm91bmQ6bmV3IDAgMCA1MjcuMTYgNjc0LjQ1OyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+CjxzdHlsZSB0eXBlPSJ0ZXh0L2NzcyI+Cgkuc3Qwe2ZpbGw6I0ZGRkZGRjt9Cgkuc3Qxe2ZpbGw6bm9uZTtzdHJva2U6I0ZGRkZGRjtzdHJva2Utd2lkdGg6MztzdHJva2UtbWl0ZXJsaW1pdDoxMDt9Cjwvc3R5bGU+CjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0yNTAuNDUsMzQ3LjYySDExMi4zOWMwLTAuMDEsMC0wLjAyLDAtMC4wMmwtMC4wMSwwLjAxbDAtMTg0LjQ5YzAtMzEuMDMtMjUuMTUtNTYuMTgtNTYuMTgtNTYuMTgKCWMwLDAsMCwwLTAuMDEsMEMyNS4xNiwxMDYuOTMsMCwxMzIuMDksMCwxNjMuMTFsMCwyNDAuNjJjMCwyOS44OSwyMi4wOCw1NC4yOSw1MS40OSw1Ni4wNGMxLjU4LDAuMTMsMy4xNiwwLjIyLDQuNzcsMC4yMgoJbDg5LjkxLTAuMTRsMzQuMzktMC4wMmwwLjAzLTAuMDNsMi4wMSwwTDI1MC40NSwzNDcuNjJ6Ii8+CjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik01MDMuODcsMjg2Ljc1Yy0xLjMyLTAuOTYtMi42OC0xLjg5LTQuMS0yLjc1TDM4OC43LDIxNi43OGwtMC4wMSwwbDAsMGwtMTAuNTUtNi4zOWwtMzIuMDItMTcuOTlsLTI5LjU1LDQ4LjMzCglsLTI4LjM5LDQ2LjMxbDEwNS4zMSw2My45YzAsMC4wMS0wLjAxLDAuMDMtMC4wMSwwLjAzbDAuMDIsMGwtOTUuNzIsMTU3LjcyYy0xNi4wOSwyNi41My03LjY0LDYxLjA5LDE4Ljg5LDc3LjE4CgljMjYuNTMsMTYuMSw2MS4wOSw3LjY0LDc3LjE4LTE4Ljg5bDEyNC44My0yMDUuNzFDNTM0LjE2LDMzNS43Nyw1MjcuOTgsMzAzLjUyLDUwMy44NywyODYuNzV6Ii8+CjxsaW5lIGNsYXNzPSJzdDEiIHgxPSI1Ni45NyIgeTE9IjY2NS4yNCIgeDI9IjQ2My43OCIgeTI9IjAiLz4KPC9zdmc+Cg==';
        $icon_data_uri = 'data:image/svg+xml;base64,' . $icon_base64;
        // Get current view parameter
        $current_view = ( isset( $_GET['view'] ) ? $_GET['view'] : '' );
        // The free title is the default and the premium guard only overrides it: a free `else`
        // behind a premium `if` is stripped along with the `if`, which left the free build with no
        // top-level menu at all (WordPress then answers "not allowed to access this page").
        $menu_title = 'LPagery';
        add_menu_page(
            $menu_title,
            $menu_title,
            'manage_options',
            'lpagery',
            [self::class, 'render_app'],
            $icon_data_uri
        );
        // Add submenu items for Pro version
        add_submenu_page(
            'lpagery',
            'Overview',
            'Overview',
            'manage_options',
            'lpagery&view=overview',
            [self::class, 'render_app']
        );
        add_submenu_page(
            'lpagery',
            'Create Pages',
            'Create Pages',
            'manage_options',
            'lpagery&view=create',
            [self::class, 'render_app']
        );
        add_submenu_page(
            'lpagery',
            'Update Pages',
            'Update Pages',
            'manage_options',
            'lpagery&view=update',
            [self::class, 'render_app']
        );
        add_submenu_page(
            'lpagery',
            'Manage Pages',
            'Manage Pages',
            'manage_options',
            'lpagery&view=manage',
            [self::class, 'render_app']
        );
        add_submenu_page(
            'lpagery',
            'Views',
            'Views',
            'manage_options',
            'lpagery&view=views',
            [self::class, 'render_app']
        );
        add_submenu_page(
            'lpagery',
            'Settings',
            'Settings',
            'manage_options',
            'lpagery&view=settings',
            [self::class, 'render_app']
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
        SuiteOAuthAuthorizeHandler::maybe_handle();
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
        if ( !lpagery_root()->trackingPermissionService()->getPermissions()->getIntercom() ) {
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

    public static function render_app() : void {
        printf( '<div id="lpagery-container" class="lpagery-tailwind" ></div>' );
    }

    public static function suppress_all_admin_notices() {
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'toplevel_page_lpagery' ) !== false ) {
            // Replace 'LPagery' with your plugin's screen ID if different
            remove_all_actions( 'admin_notices' );
        }
    }

}
