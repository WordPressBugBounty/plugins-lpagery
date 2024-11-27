<?php

namespace LPagery;

use LPagery\controller\CreatePostController;
use LPagery\data\LPageryDao;
use LPagery\factories\CreatePostDelegateFactory;
use LPagery\factories\DuplicateSlugHandlerFactory;
use LPagery\factories\DynamicPageAttributeHandlerFactory;
use LPagery\factories\InputParamProviderFactory;
use LPagery\factories\PageUpdateDataHandlerFactory;
use LPagery\factories\SubstitutionHandlerFactory;
use LPagery\io\Api;
use LPagery\io\Mapper;
use LPagery\model\BaseParams;
use LPagery\service\media\AttachmentHelper;
use LPagery\service\PageExportHandler;
use LPagery\service\settings\SettingsController;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\service\taxonomies\TaxonomyOutputHandler;
use LPagery\utils\MemoryUtils;
use LPagery\utils\Utils;
use Throwable;
use WP_Error;
use WP_REST_Request;
add_action( 'wp_ajax_lpagery_fetch_permalink', 'LPagery\\lpagery_fetch_permalink' );
function lpagery_fetch_permalink() {
    check_ajax_referer( 'lpagery_ajax' );
    $post_id = (int) $_GET['post_id'];
    if ( empty( $_GET['slug'] ) ) {
        return false;
    }
    $slug = strtolower( lpagery_sanitize_title_with_dashes( $_GET['slug'] ) );
    echo site_url( get_page_uri( $post_id ) . "/" . $slug );
    wp_die();
}

add_action( 'wp_ajax_lpagery_custom_sanitize_title', 'LPagery\\lpagery_custom_sanitize_title' );
function lpagery_custom_sanitize_title() {
    check_ajax_referer( 'lpagery_ajax' );
    if ( empty( $_GET['slug'] ) ) {
        return false;
    }
    $slug = strtolower( urldecode( lpagery_sanitize_title_with_dashes( $_GET['slug'] ) ) );
    echo esc_html( $slug );
    wp_die();
}

add_action( 'wp_ajax_lpagery_create_posts', 'LPagery\\lpagery_create_posts' );
function lpagery_create_posts() {
    $createPostController = CreatePostController::get_instance( CreatePostDelegateFactory::create(), LPageryDao::get_instance() );
    try {
        $result = $createPostController->lpagery_create_posts_ajax( $_POST );
    } catch ( Throwable $exception ) {
        $result = array(
            "success"   => false,
            "exception" => $exception->__toString(),
        );
    }
    print_r( json_encode( $result ) );
    wp_die();
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'lpagery/v1', '/create_posts', array(
        'methods'             => 'POST',
        'callback'            => 'LPagery\\lpagery_rest_create_posts',
        'permission_callback' => '__return_true',
    ) );
} );
function lpagery_rest_create_posts(  WP_REST_Request $request  ) {
    ob_start();
    // Start output buffering
    $createPostController = CreatePostController::get_instance( CreatePostDelegateFactory::create(), LPageryDao::get_instance() );
    $nonce = $request->get_param( 'nonce' );
    if ( !wp_verify_nonce( $nonce, 'lpagery_create_post' ) ) {
        return new WP_Error('invalid_nonce', 'Invalid nonce', array(
            'status' => 403,
        ));
    }
    try {
        $result = $createPostController->lpagery_create_posts_rest( $request );
        return rest_ensure_response( $result );
    } catch ( Throwable $exception ) {
        $output = ob_get_clean();
        // Get buffer content and clean buffer
        return rest_ensure_response( array(
            "success"   => false,
            "exception" => $output . $exception->__toString(),
        ) );
    } finally {
        ob_get_clean();
    }
}

add_action( 'wp_ajax_lpagery_save_settings', 'LPagery\\lpagery_save_settings' );
function lpagery_save_settings() {
    check_ajax_referer( 'lpagery_ajax' );
    $settings = Utils::lpagery_sanitize_object( $_POST['settings'] );
    $spintax_enabled = rest_sanitize_boolean( $settings['spintax'] );
    $consistent_update = rest_sanitize_boolean( $settings['consistent_update'] );
    $image_processing_enabled = rest_sanitize_boolean( $settings['image_processing'] );
    $author_id = intval( $settings['author_id'] );
    $google_sync_interval = sanitize_text_field( $settings['google_sheet_sync_interval'] );
    $lpagery_google_sync_type = sanitize_text_field( $settings['google_sheet_sync_type'] );
    $schedules = array_keys( wp_get_schedules() );
    if ( !in_array( $google_sync_interval, $schedules ) ) {
        $google_sync_interval = null;
    }
    $custom_post_types = ( isset( $settings['custom_post_types'] ) ? array_map( 'sanitize_text_field', $settings['custom_post_types'] ) : array() );
    $next_google_sheet_sync = null;
    if ( isset( $settings["next_google_sheet_sync"] ) ) {
        $next_google_sheet_sync = strtotime( get_gmt_from_date( $settings["next_google_sheet_sync"] ) );
    }
    SettingsController::get_instance()->lpagery_save_settings(
        $consistent_update,
        $spintax_enabled,
        $custom_post_types,
        $image_processing_enabled,
        $author_id,
        $google_sync_interval,
        $next_google_sheet_sync,
        $lpagery_google_sync_type
    );
    wp_die();
}

add_action( 'wp_ajax_lpagery_get_settings', 'LPagery\\lpagery_get_settings' );
function lpagery_get_settings() {
    check_ajax_referer( 'lpagery_ajax' );
    print_r( SettingsController::get_instance()->lpagery_get_settings() );
    wp_die();
}

add_action( 'wp_ajax_lpagery_get_pages', 'LPagery\\lpagery_get_pages' );
function lpagery_get_pages() {
    check_ajax_referer( 'lpagery_ajax' );
    $user_option = maybe_unserialize( get_user_option( 'lpagery_settings', get_current_user_id() ) );
    $custom_post_types = (array) $user_option['custom_post_types'];
    array_push( $custom_post_types, "page", "post" );
    $mode = sanitize_text_field( $_GET["mode"] );
    $select = sanitize_text_field( $_GET["select"] );
    $post_id = null;
    if ( array_key_exists( "post_id", $_GET ) ) {
        $post_id = intval( $_GET["post_id"] );
    }
    $posts = LPageryDao::get_instance()->lpagery_search_posts(
        ( isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : "" ),
        $custom_post_types,
        $mode,
        $select,
        $post_id
    );
    if ( $select === "lpagery_parent_path" ) {
        array_unshift( $posts, (object) [
            'ID'         => 0,
            'post_title' => 'Select page',
        ] );
    }
    // Get the instance of the mapper
    $mapper = Mapper::get_instance();
    $mapped_posts = array_map( [$mapper, 'lpagery_map_post'], $posts );
    print_r( json_encode( $mapped_posts ) );
    wp_die();
}

add_action( 'wp_ajax_lpagery_get_post_type', 'LPagery\\lpagery_get_post_type' );
function lpagery_get_post_type() {
    check_ajax_referer( 'lpagery_ajax' );
    $post_id = (int) $_GET['post_id'];
    $process_id = (int) $_GET['process_id'];
    if ( $process_id ) {
        $LPageryDao = LPageryDao::get_instance();
        $process_by_id = $LPageryDao->lpagery_get_process_by_id( $process_id );
        $count = $LPageryDao->lpagery_count_processes();
        $post = get_post( $process_by_id->post_id );
        $lpagery_first_process_date = $LPageryDao->lpagery_get_first_process_date();
        echo json_encode( array(
            'type'               => $post->post_type,
            "process_count"      => $count,
            "first_process_date" => $lpagery_first_process_date,
        ) );
        wp_die();
    }
    $post = get_post( $post_id );
    echo esc_html( $post->post_type );
    wp_die();
}

add_action( 'wp_ajax_lpagery_search_processes', 'LPagery\\lpagery_search_processes' );
function lpagery_search_processes() {
    $LPageryDao = LPageryDao::get_instance();
    check_ajax_referer( 'lpagery_ajax' );
    $post_id = (int) $_GET['post_id'];
    $user_id = (int) $_GET['user_id'];
    $search_term = sanitize_text_field( urldecode( $_GET['purpose'] ) );
    $lpagery_processes = $LPageryDao->lpagery_search_processes( $post_id, $user_id, $search_term );
    if ( is_null( $lpagery_processes ) ) {
        return json_encode( array() );
    }
    $mapper = Mapper::get_instance();
    $return_value = array_map( [$mapper, 'lpagery_map_process_search'], $lpagery_processes );
    print_r( json_encode( $return_value, JSON_NUMERIC_CHECK ) );
    wp_die();
}

add_action( 'wp_ajax_lpagery_get_ram_usage', 'LPagery\\lpagery_get_ram_usage' );
function lpagery_get_ram_usage() {
    check_ajax_referer( 'lpagery_ajax' );
    print_r( json_encode( MemoryUtils::lpagery_get_memory_usage() ) );
    wp_die();
}

add_action( 'wp_ajax_lpagery_get_post_title', 'LPagery\\lpagery_get_post_title' );
function lpagery_get_post_title() {
    check_ajax_referer( 'lpagery_ajax' );
    $post_id = (int) $_GET['post_id'];
    $post = get_post( $post_id );
    echo esc_html( $post->post_title );
    wp_die();
}

add_action( 'wp_ajax_lpagery_get_users', 'LPagery\\lpagery_get_users' );
function lpagery_get_users() {
    check_ajax_referer( 'lpagery_ajax' );
    $LPageryDao = LPageryDao::get_instance();
    print_r( json_encode( $LPageryDao->lpagery_get_users_with_processes(), JSON_NUMERIC_CHECK ) );
    wp_die();
}

add_action( 'wp_ajax_lpagery_get_template_posts', 'LPagery\\lpagery_get_template_posts' );
function lpagery_get_template_posts() {
    check_ajax_referer( 'lpagery_ajax' );
    $LPageryDao = LPageryDao::get_instance();
    print_r( json_encode( $LPageryDao->lpagery_get_template_posts(), JSON_NUMERIC_CHECK ) );
    wp_die();
}

add_action( 'wp_ajax_lpagery_upsert_process', 'LPagery\\lpagery_upsert_process' );
function lpagery_upsert_process() {
    check_ajax_referer( 'lpagery_ajax' );
    try {
        $post_id = ( isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : null );
        $process_id = ( isset( $_POST['id'] ) ? (int) $_POST['id'] : -1 );
        $purpose = ( isset( $_POST['purpose'] ) ? sanitize_text_field( $_POST['purpose'] ) : null );
        $LPageryDao = LPageryDao::get_instance();
        $process = $LPageryDao->lpagery_get_process_by_id( $process_id );
        $request_google_sheet_data = $_POST["google_sheet_data"];
        $google_sheet_enabled = filter_var( $request_google_sheet_data["enabled"], FILTER_VALIDATE_BOOLEAN );
        $google_sheet_sync_enabled = filter_var( $request_google_sheet_data["sync_enabled"], FILTER_VALIDATE_BOOLEAN );
        $add = filter_var( $request_google_sheet_data["add"], FILTER_VALIDATE_BOOLEAN );
        $update = filter_var( $request_google_sheet_data["update"], FILTER_VALIDATE_BOOLEAN );
        $delete = filter_var( $request_google_sheet_data["delete"], FILTER_VALIDATE_BOOLEAN );
        $sheet_url = filter_var( urldecode( $request_google_sheet_data["url"] ), FILTER_VALIDATE_URL );
        $google_sheet_data = null;
        if ( $google_sheet_enabled ) {
            $google_sheet_data = array(
                "url"    => $sheet_url,
                "add"    => $add,
                "update" => $update,
                "delete" => $delete,
            );
        }
        $data = ( isset( $_POST['data'] ) ? lpagery_extract_process_data( $_POST['data'], $process ) : null );
        $lpagery_process_id = $LPageryDao->lpagery_upsert_process(
            $post_id,
            $process_id,
            $purpose,
            $data,
            $google_sheet_data,
            $google_sheet_sync_enabled
        );
        print_r( json_encode( array(
            "success"    => true,
            "process_id" => $lpagery_process_id,
        ) ) );
    } catch ( Throwable $exception ) {
        print_r( json_encode( array(
            "success"   => false,
            "exception" => $exception->__toString(),
        ) ) );
        wp_die();
    }
    wp_die();
}

function lpagery_extract_process_data(  $input_data, $process  ) {
    check_ajax_referer( 'lpagery_ajax' );
    if ( !$input_data ) {
        return null;
    }
    $categories = ( isset( $input_data['categories'] ) ? $input_data['categories'] : array() );
    $categories = array_map( 'sanitize_text_field', $categories );
    $tags = $input_data['tags'] ?? array();
    $tags = array_map( 'sanitize_text_field', $tags );
    $parent_path = (int) $input_data['parent_path'];
    $slug = sanitize_text_field( $input_data['slug'] );
    $status = sanitize_text_field( $input_data['status'] );
    if ( isset( $process ) ) {
        if ( $status == "-1" ) {
            $unserialized_data = maybe_unserialize( $process->data );
            $status = $unserialized_data['status'] ?? get_post_status( $process->post_id );
        }
    }
    $data = array(
        "categories"  => $categories,
        "tags"        => $tags,
        "status"      => $status,
        "parent_path" => $parent_path,
        "slug"        => $slug,
    );
    return $data;
}

add_action( 'wp_ajax_lpagery_get_duplicated_slugs', 'LPagery\\lpagery_get_duplicated_slugs' );
function lpagery_get_duplicated_slugs() {
    check_ajax_referer( 'lpagery_ajax' );
    try {
        $slug = ( isset( $_POST['slug'] ) ? lpagery_sanitize_title_with_dashes( $_POST['slug'] ) : null );
        $process_id = ( isset( $_POST['process_id'] ) ? intval( $_POST['process_id'] ) : -1 );
        $data = $_POST['data'];
        $template_id = intval( $_POST['post_id'] );
        $duplicateSlugHandler = DuplicateSlugHandlerFactory::create();
        echo json_encode( $duplicateSlugHandler->lpagery_get_duplicated_slugs(
            $data,
            $process_id,
            $slug,
            $template_id
        ) );
    } catch ( Throwable $throwable ) {
        echo json_encode( array(
            "success"   => false,
            "exception" => $throwable->__toString(),
        ) );
    }
    wp_die();
}

add_action( 'wp_ajax_lpagery_download_post_json', 'LPagery\\lpagery_download_post_json' );
function lpagery_download_post_json() {
    check_ajax_referer( 'lpagery_ajax' );
    $process_id = intval( $_GET["process_id"] );
    $pageExportHandler = PageExportHandler::get_instance();
    // Fetch the data to be exported
    $pageExportHandler->export( $process_id );
    // Set the headers for file download
    exit;
}
