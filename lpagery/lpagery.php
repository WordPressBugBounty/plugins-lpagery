<?php

/*
Plugin Name: LPagery
Plugin URI: https://lpagery.io/
Description: Create hundreds or even thousands of landingpages for local businesses, services etc.
Version: 1.5.6
Author: LPagery
License: GPLv2 or later
*/
// Create a helper function for easy SDK access.
use LPagery\service\settings\SettingsController;
use LPagery\service\sheet_sync\GoogleSheetSyncControllerFactory;
use LPagery\data\LPageryDao;
use LPagery\io\Mapper;
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
                        'slug' => 'lpagery',
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
    require "vendor/autoload.php";
    $plugin_data = get_file_data( __FILE__, array(
        'Version' => 'Version',
    ) );
    $lpagery_version = $plugin_data['Version'];
    define( 'LPAGERY_VERSION', $lpagery_version );
    function lpagery_activate() {
        LPageryDao::get_instance()->init_db();
    }

    register_activation_hook( __FILE__, 'lpagery_activate' );
    add_action( 'admin_menu', 'lpagery_setup_menu' );
    function lpagery_setup_menu() {
        $icon_base64 = 'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPCEtLSBHZW5lcmF0b3I6IEFkb2JlIElsbHVzdHJhdG9yIDI2LjIuMSwgU1ZHIEV4cG9ydCBQbHVnLUluIC4gU1ZHIFZlcnNpb246IDYuMDAgQnVpbGQgMCkgIC0tPgo8c3ZnIHZlcnNpb249IjEuMSIgaWQ9IkViZW5lXzEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4IgoJIHZpZXdCb3g9IjAgMCA1MjcuMTYgNjc0LjQ1IiBzdHlsZT0iZW5hYmxlLWJhY2tncm91bmQ6bmV3IDAgMCA1MjcuMTYgNjc0LjQ1OyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+CjxzdHlsZSB0eXBlPSJ0ZXh0L2NzcyI+Cgkuc3Qwe2ZpbGw6I0ZGRkZGRjt9Cgkuc3Qxe2ZpbGw6bm9uZTtzdHJva2U6I0ZGRkZGRjtzdHJva2Utd2lkdGg6MztzdHJva2UtbWl0ZXJsaW1pdDoxMDt9Cjwvc3R5bGU+CjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0yNTAuNDUsMzQ3LjYySDExMi4zOWMwLTAuMDEsMC0wLjAyLDAtMC4wMmwtMC4wMSwwLjAxbDAtMTg0LjQ5YzAtMzEuMDMtMjUuMTUtNTYuMTgtNTYuMTgtNTYuMTgKCWMwLDAsMCwwLTAuMDEsMEMyNS4xNiwxMDYuOTMsMCwxMzIuMDksMCwxNjMuMTFsMCwyNDAuNjJjMCwyOS44OSwyMi4wOCw1NC4yOSw1MS40OSw1Ni4wNGMxLjU4LDAuMTMsMy4xNiwwLjIyLDQuNzcsMC4yMgoJbDg5LjkxLTAuMTRsMzQuMzktMC4wMmwwLjAzLTAuMDNsMi4wMSwwTDI1MC40NSwzNDcuNjJ6Ii8+CjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik01MDMuODcsMjg2Ljc1Yy0xLjMyLTAuOTYtMi42OC0xLjg5LTQuMS0yLjc1TDM4OC43LDIxNi43OGwtMC4wMSwwbDAsMGwtMTAuNTUtNi4zOWwtMzIuMDItMTcuOTlsLTI5LjU1LDQ4LjMzCglsLTI4LjM5LDQ2LjMxbDEwNS4zMSw2My45YzAsMC4wMS0wLjAxLDAuMDMtMC4wMSwwLjAzbDAuMDIsMGwtOTUuNzIsMTU3LjcyYy0xNi4wOSwyNi41My03LjY0LDYxLjA5LDE4Ljg5LDc3LjE4CgljMjYuNTMsMTYuMSw2MS4wOSw3LjY0LDc3LjE4LTE4Ljg5bDEyNC44My0yMDUuNzFDNTM0LjE2LDMzNS43Nyw1MjcuOTgsMzAzLjUyLDUwMy44NywyODYuNzV6Ii8+CjxsaW5lIGNsYXNzPSJzdDEiIHgxPSI1Ni45NyIgeTE9IjY2NS4yNCIgeDI9IjQ2My43OCIgeTI9IjAiLz4KPC9zdmc+Cg==';
        $icon_data_uri = 'data:image/svg+xml;base64,' . $icon_base64;
        add_menu_page(
            'LPagery',
            'LPagery',
            'manage_options',
            'lpagery',
            'init',
            $icon_data_uri
        );
    }

    include_once plugin_dir_path( __FILE__ ) . '/src/io/AjaxActions.php';
    include_once plugin_dir_path( __FILE__ ) . '/src/data/LPageryDao.php';
    function lpagery_get_placeholder_counts() {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $result = $wpdb->get_row( "SELECT exists(select *\n              FROM INFORMATION_SCHEMA.TABLES\n              WHERE table_name = '{$table_name_process}'\n                and create_time <= '2023-09-04 00:00:00') as created" );
        if ( $result->created ) {
            return null;
        }
        if ( lpagery_fs()->is_free_plan() ) {
            return array(
                "placeholders" => 3,
            );
        } else {
            return array(
                "placeholders" => null,
            );
        }
    }

    function lpagery_get_installation_date() : ?DateTime {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $result = $wpdb->get_row( "SELECT create_time\n              FROM INFORMATION_SCHEMA.TABLES\n              WHERE table_name = '{$table_name_process}'" );
        try {
            return new DateTime($result->create_time);
        } catch ( Exception $e ) {
            return null;
        }
    }

    function lpagery_info_log(  $message  ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $message );
        }
    }

    function lpagery_enqueue_scripts() {
        include_once plugin_dir_path( __FILE__ ) . '/src/includes/Enqueues.php';
    }

    add_action( 'admin_enqueue_scripts', 'lpagery_enqueue_scripts' );
    function init() {
        include_once plugin_dir_path( __FILE__ ) . '/src/views/main.php';
        LPageryDao::get_instance()->init_db();
    }

    add_filter( 'posts_where', 'lpagery_source_filter' );
    function lpagery_source_filter(  $where  ) {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        if ( !isset( $_GET['lpagery_process'] ) && !isset( $_GET['lpagery_template'] ) ) {
            return $where;
        }
        if ( isset( $_GET['lpagery_template'] ) ) {
            $lpagery_template_id = $_GET['lpagery_template'];
            if ( $lpagery_template_id != '' ) {
                $lpagery_template_id = intval( $lpagery_template_id );
                $where .= " AND EXISTS (select pp.id\n                    from {$table_name_process_post} pp\n                    where pp.template_id = {$lpagery_template_id} and pp.post_id = {$wpdb->posts}.id)";
                return $where;
            }
        } else {
            $lpagery_process_id = $_GET['lpagery_process'];
            if ( $lpagery_process_id != '' ) {
                $lpagery_process_id = intval( $lpagery_process_id );
                $where .= " AND EXISTS (select pp.id\n                    from {$table_name_process_post} pp\n                          \n                    where pp.lpagery_process_id = {$lpagery_process_id} and pp.post_id = {$wpdb->posts}.id)";
                return $where;
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
        $plan_post_created = get_post_meta( $post_id, '_lpagery_free_plan', true );
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
            $title = $title ?? $found_post['post_title'];
            return '<a class="lpagery_link_anchor" href="' . esc_url( get_page_link( $found_post['id'] ) ) . '" target="' . esc_attr( $target ) . '">' . esc_html( $title ) . '</a>';
        }
        return null;
    }

    function lpagery_time_ago(  $timestamp  ) {
        $current_time = new DateTime();
        $time_to_compare = DateTime::createFromFormat( 'U', $timestamp );
        $time_difference = $current_time->getTimestamp() - $time_to_compare->getTimestamp();
        $is_future = $time_difference < 0;
        $time_difference = abs( $time_difference );
        $units = [
            "year"   => 365 * 24 * 60 * 60,
            "month"  => 30 * 24 * 60 * 60,
            "week"   => 7 * 24 * 60 * 60,
            "day"    => 24 * 60 * 60,
            "hour"   => 60 * 60,
            "minute" => 60,
            "second" => 1,
        ];
        foreach ( $units as $unit => $value ) {
            if ( $time_difference >= $value ) {
                $unit_value = floor( $time_difference / $value );
                $suffix = ( $unit_value == 1 ? "" : "s" );
                $direction = ( $is_future ? "from now" : "ago" );
                return "{$unit_value} {$unit}{$suffix} {$direction}";
            }
        }
        return "just now";
    }

    function lpagery_add_replace_filename(  $form_fields, $post  ) {
        $settingsController = SettingsController::get_instance();
        if ( $settingsController->lpagery_get_image_processing_enabled() ) {
            $form_fields['lpagery_replace_filename'] = array(
                'label' => '<img width="25px" height ="25px" src="' . plugin_dir_url( dirname( __FILE__ ) ) . "/" . plugin_basename( dirname( __FILE__ ) ) . '/freemius/assets/img/lpagery.png"/>Download Filename',
                'input' => 'text',
                'value' => get_post_meta( $post->ID, '_lpagery_replace_filename', true ),
                'helps' => 'The name for LPagery to be taken for downloading images when using this image as an placeholder. The ending will be populated automatically. Please add placeholders from the input file here (e.g. "my-image-in-{city}")',
            );
        }
        return $form_fields;
    }

    add_filter(
        'attachment_fields_to_edit',
        'lpagery_add_replace_filename',
        10,
        2
    );
    function lpagery_save_replace_filename_field(  $post, $attachment  ) {
        if ( isset( $attachment['lpagery_replace_filename'] ) ) {
            // Update or add the custom field value
            update_post_meta( $post['ID'], '_lpagery_replace_filename', $attachment['lpagery_replace_filename'] );
        }
        return $post;
    }

    add_filter(
        'attachment_fields_to_save',
        'lpagery_save_replace_filename_field',
        10,
        2
    );
    if ( lpagery_fs()->is_free_plan() ) {
        wp_clear_scheduled_hook( "lpagery_sync_google_sheet" );
    }
    function lpagery_sanitize_title_with_dashes(  $title, $raw_title = '', $context = 'save'  ) {
        $search = array("ä", "ü", "ö");
        $replace = array("ae", "ue", "oe");
        $title = str_replace( $search, $replace, $title );
        $title = strip_tags( $title );
        // Preserve escaped octets.
        $title = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title );
        // Remove percent signs that are not part of an octet.
        $title = str_replace( '%', '', $title );
        // Restore octets.
        $title = preg_replace( '|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title );
        if ( seems_utf8( $title ) ) {
            if ( function_exists( 'mb_strtolower' ) ) {
                $title = mb_strtolower( $title, 'UTF-8' );
            }
            $title = utf8_uri_encode( $title, 200 );
        }
        $title = strtolower( $title );
        if ( 'save' === $context ) {
            // Convert &nbsp, &ndash, and &mdash to hyphens.
            $title = str_replace( array('%c2%a0', '%e2%80%93', '%e2%80%94'), '-', $title );
            // Convert &nbsp, &ndash, and &mdash HTML entities to hyphens.
            $title = str_replace( array(
                '&nbsp;',
                '&#160;',
                '&ndash;',
                '&#8211;',
                '&mdash;',
                '&#8212;'
            ), '-', $title );
            // Convert forward slash to hyphen.
            $title = str_replace( '/', '-', $title );
            // Strip these characters entirely.
            $title = str_replace( array(
                // Soft hyphens.
                '%c2%ad',
                // &iexcl and &iquest.
                '%c2%a1',
                '%c2%bf',
                // Angle quotes.
                '%c2%ab',
                '%c2%bb',
                '%e2%80%b9',
                '%e2%80%ba',
                // Curly quotes.
                '%e2%80%98',
                '%e2%80%99',
                '%e2%80%9c',
                '%e2%80%9d',
                '%e2%80%9a',
                '%e2%80%9b',
                '%e2%80%9e',
                '%e2%80%9f',
                // Bullet.
                '%e2%80%a2',
                // &copy, &reg, &deg, &hellip, and &trade.
                '%c2%a9',
                '%c2%ae',
                '%c2%b0',
                '%e2%80%a6',
                '%e2%84%a2',
                // Acute accents.
                '%c2%b4',
                '%cb%8a',
                '%cc%81',
                '%cd%81',
                // Grave accent, macron, caron.
                '%cc%80',
                '%cc%84',
                '%cc%8c',
            ), '', $title );
            // Convert &times to 'x'.
            $title = str_replace( '%c3%97', 'x', $title );
        }
        // Kill entities.
        $title = preg_replace( '/&.+?;/', '', $title );
        $title = str_replace( '.', '-', $title );
        $title = preg_replace( '/[^%a-z0-9 {}_-]/', '', $title );
        $title = preg_replace( '/\\s+/', '-', $title );
        $title = preg_replace( '|-+|', '-', $title );
        $title = trim( $title, '-' );
        return $title;
    }

}