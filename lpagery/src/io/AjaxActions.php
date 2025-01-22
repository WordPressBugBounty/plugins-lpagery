<?php

namespace LPagery;

use LPagery\controller\CreatePostController;
use LPagery\data\LPageryDao;
use LPagery\data\SearchPostService;
use LPagery\factories\CreatePostDelegateFactory;
use LPagery\factories\DuplicateSlugHandlerFactory;
use LPagery\io\Mapper;
use LPagery\model\ProcessSheetSyncParams;
use LPagery\service\onboarding\OnboardingService;
use LPagery\service\PageExportHandler;
use LPagery\service\settings\SettingsController;
use LPagery\utils\MemoryUtils;
use LPagery\utils\Utils;
use LPagery\wpml\WpmlHelper;

add_action('wp_ajax_lpagery_sanitize_slug', 'LPagery\lpagery_sanitize_slug');

function lpagery_sanitize_slug()
{
    check_ajax_referer('lpagery_ajax');
    $parent_id = (int)$_POST['parent_id'];
    if (empty($_POST['slug'])) {
        return '';
    }
    $slug = strtolower(Utils::lpagery_sanitize_title_with_dashes($_POST['slug']));

    echo json_encode(["url" => site_url(get_page_uri($parent_id) . "/" . $slug)]);
    wp_die();
}

add_action('wp_ajax_lpagery_custom_sanitize_title', 'LPagery\lpagery_custom_sanitize_title');

function lpagery_custom_sanitize_title()
{
    check_ajax_referer('lpagery_ajax');
    if (empty($_POST['slug'])) {
        return false;
    }
    $slug = (strtolower(urldecode(Utils::lpagery_sanitize_title_with_dashes($_POST['slug']))));

    echo esc_html($slug);
    wp_die();
}

add_action('wp_ajax_lpagery_create_posts', 'LPagery\lpagery_create_posts');

function lpagery_create_posts()
{
    ob_start();

    try {
        $createPostController = CreatePostController::get_instance(CreatePostDelegateFactory::create(),
            LPageryDao::get_instance(), SettingsController::get_instance());
        $result = $createPostController->lpagery_create_posts_ajax($_POST);
        $ob_get_contents = ob_get_clean();
    } catch (\Throwable $exception) {
        {
            $ob_get_contents = ob_get_clean();
            $result = array("success" => false,
                "exception" => $exception->__toString() . $ob_get_contents);
        }
    }
    if ($ob_get_contents) {
        $result["buffer"] = $ob_get_contents;
    }

    print_r(json_encode($result));
    wp_die();
}


add_action('wp_ajax_lpagery_get_settings', 'LPagery\lpagery_get_settings');
function lpagery_get_settings()
{
    check_ajax_referer('lpagery_ajax');
    $settings = SettingsController::get_instance()->getSettings();
    print_r(json_encode($settings));
    wp_die();
}

add_action('wp_ajax_lpagery_get_batch_size', 'LPagery\lpagery_get_batch_size');
function lpagery_get_batch_size()
{
    check_ajax_referer('lpagery_ajax');
    $batch_size = SettingsController::get_instance()->getBatchSize();
    print_r(json_encode(array("batch_size" => $batch_size)));
    wp_die();
}

add_action('wp_ajax_lpagery_get_pages', 'LPagery\lpagery_get_pages');
function lpagery_get_pages()
{
    check_ajax_referer('lpagery_ajax');
    $custom_post_types = SettingsController::get_instance()->getEnabledCustomPostTypes();
    array_push($custom_post_types, "page", "post");
    $mode = sanitize_text_field($_POST["mode"]);
    $select = sanitize_text_field($_POST["select"]);
    $template_id = null;
    if (array_key_exists("template_id", $_POST)) {
        $template_id = intval($_POST["template_id"]);
    }
    $posts = SearchPostService::get_instance()->lpagery_search_posts(isset($_POST['search']) ? sanitize_text_field($_POST['search']) : "",
        $custom_post_types, $mode, $select, $template_id);


    // Get the instance of the mapper
    $mapper = Mapper::get_instance();

    // Map posts using the mapper instance
    $mapped_posts = array_map([$mapper,
        'lpagery_map_post'], $posts);
    echo json_encode($mapped_posts);
    wp_die();
}


add_action('wp_ajax_lpagery_get_taxonomy_terms', 'LPagery\lpagery_get_taxonomy_terms');
function lpagery_get_taxonomy_terms()
{
    check_ajax_referer('lpagery_ajax');

    $categories = get_terms(array('hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC'));

    $result = [];
    foreach ($categories as $category) {
        // Only add unique term IDs
        if (!isset($result[$category->taxonomy][$category->term_id])) {
            $result[$category->taxonomy][$category->term_id] = ["id" => $category->term_id,
                "name" => $category->name];
        }
    }

    // Reformat result to remove keys as term IDs
    foreach ($result as $taxonomy => $terms) {
        $result[$taxonomy] = array_values($terms);
    }

    echo json_encode($result);
    wp_die();
}

add_action('wp_ajax_lpagery_get_taxonomies', 'LPagery\lpagery_get_taxonomies');
function lpagery_get_taxonomies()
{
    check_ajax_referer('lpagery_ajax');
    $post_type = isset($_POST["post_type"]) ? sanitize_text_field($_POST["post_type"]) : null;
    if (!$post_type) {
        $taxonomies = get_taxonomies(array(), 'objects');
    } else {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
    }


    $result = array_map(function ($taxonomy) {
        return array("name" => $taxonomy->name,
            "label" => $taxonomy->label != null ? $taxonomy->label : $taxonomy->name);
    }, $taxonomies);
    echo(json_encode(array_values($result)));
    wp_die();
}


add_action('wp_ajax_lpagery_get_post_type', 'LPagery\lpagery_get_post_type');
function lpagery_get_post_type()
{
    check_ajax_referer('lpagery_ajax');
    $post_id = (int)$_POST['post_id'];
    $process_id = (int)$_POST['process_id'];
    if ($process_id) {
        $LPageryDao = LPageryDao::get_instance();
        $process_by_id = $LPageryDao->lpagery_get_process_by_id($process_id);
        $count = $LPageryDao->lpagery_count_processes();
        $post = get_post($process_by_id->post_id);
        $lpagery_first_process_date = $LPageryDao->lpagery_get_first_process_date();
        echo json_encode(array('type' => $post->post_type,
            "process_count" => $count,
            "first_process_date" => $lpagery_first_process_date));
        wp_die();
    }
    $post = get_post($post_id);
    echo esc_html($post->post_type);
    wp_die();
}


add_action('wp_ajax_lpagery_search_processes', 'LPagery\lpagery_search_processes');
function lpagery_search_processes()
{
    $LPageryDao = LPageryDao::get_instance();
    check_ajax_referer('lpagery_ajax');
    $post_id = (int)($_POST['post_id'] ?? null);
    $user_id = (int)(($_POST['user_id'] ?? null));
    $search_term = sanitize_text_field(urldecode($_POST['purpose'] ?? ""));
    $empty_filter = sanitize_text_field(urldecode($_POST['empty_filter'] ?? ""));
    $lpagery_processes = $LPageryDao->lpagery_search_processes($post_id, $user_id, $search_term, $empty_filter);
    if (is_null($lpagery_processes)) {
        return json_encode(array());
    }
    $mapper = Mapper::get_instance();

    $return_value = (array_map([$mapper,
        'lpagery_map_process_search'], $lpagery_processes));

    print_r(json_encode($return_value, JSON_NUMERIC_CHECK));

    wp_die();
}

add_action('wp_ajax_lpagery_get_ram_usage', 'LPagery\lpagery_get_ram_usage');
function lpagery_get_ram_usage()
{
    check_ajax_referer('lpagery_ajax');
    print_r(json_encode(MemoryUtils::lpagery_get_memory_usage()));
    wp_die();
}


add_action('wp_ajax_lpagery_get_post_title_as_slug', 'LPagery\lpagery_get_post_title_as_slug');
function lpagery_get_post_title_as_slug()
{
    check_ajax_referer('lpagery_ajax');
    $post_id = (int)$_POST['post_id'];
    $post = get_post($post_id);
    $slug = strtolower(Utils::lpagery_sanitize_title_with_dashes($post->post_title));
    print_r(json_encode(array("slug" => $slug)));
    wp_die();
}


add_action('wp_ajax_lpagery_get_users', 'LPagery\lpagery_get_users');
function lpagery_get_users()
{
    check_ajax_referer('lpagery_ajax');

    $LPageryDao = LPageryDao::get_instance();
    print_r(json_encode($LPageryDao->lpagery_get_users_with_processes(), JSON_NUMERIC_CHECK));
    wp_die();
}

add_action('wp_ajax_lpagery_get_template_posts', 'LPagery\lpagery_get_template_posts');
function lpagery_get_template_posts()
{
    check_ajax_referer('lpagery_ajax');
    $LPageryDao = LPageryDao::get_instance();
    $template_posts = $LPageryDao->lpagery_get_template_posts();
    if (function_exists('wpml_get_language_information')) {
        foreach ($template_posts as &$post_array) {
            $wpmlData = WpmlHelper::get_wpml_language_data($post_array->id);
            if ($wpmlData->language_code) {
                $post_array->language_code = $wpmlData->language_code;
            }
        }
    }
    print_r(json_encode($template_posts, JSON_NUMERIC_CHECK));
    wp_die();
}

add_action('wp_ajax_lpagery_upsert_process', 'LPagery\lpagery_upsert_process');
function lpagery_upsert_process()
{
    check_ajax_referer('lpagery_ajax');
    try {
        $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : null;
        $process_id = isset($_POST['id']) ? (int)$_POST['id'] : -1;
        $purpose = isset($_POST['purpose']) ? sanitize_text_field($_POST['purpose']) : null;
        $LPageryDao = LPageryDao::get_instance();
        $process = $LPageryDao->lpagery_get_process_by_id($process_id);
        $request_google_sheet_data = $_POST["google_sheet_data"] ?? [];

        $google_sheet_enabled = filter_var($request_google_sheet_data["enabled"] ?? false, FILTER_VALIDATE_BOOLEAN);
        $google_sheet_data = null;
        $sync_enabled = false;
        $sheet_url = filter_var(urldecode($request_google_sheet_data["url"] ?? ''), FILTER_VALIDATE_URL);
        if ($google_sheet_enabled && $sheet_url) {
            $create = filter_var($request_google_sheet_data["add"] ?? false, FILTER_VALIDATE_BOOLEAN);
            $update = filter_var($request_google_sheet_data["update"] ?? false, FILTER_VALIDATE_BOOLEAN);
            $delete = filter_var($request_google_sheet_data["delete"] ?? false, FILTER_VALIDATE_BOOLEAN);
            $sync_enabled = filter_var($request_google_sheet_data["sync_enabled"] ?? false, FILTER_VALIDATE_BOOLEAN);
            $sheet_url = filter_var(urldecode($request_google_sheet_data["url"]), FILTER_VALIDATE_URL);


            $google_sheet_data = array("url" => $sheet_url,
                "add" => $create,
                "update" => $update,
                "delete" => $delete);
        }

        $data = isset($_POST['data']) ? lpagery_extract_process_data($_POST['data'], $process) : null;
        $lpagery_process_id = $LPageryDao->lpagery_upsert_process($post_id, $process_id, $purpose, $data,
            $google_sheet_data, $sync_enabled);
        if($google_sheet_enabled && $sync_enabled) {

            $update_settings = $_POST['update_settings'] ?? [];
            
            $is_force_update = !empty($update_settings['force_update']) && rest_sanitize_boolean($update_settings['force_update']);
            $is_overwrite_manual_changes = !empty($update_settings['overwrite_manual_changes']) && rest_sanitize_boolean($update_settings['overwrite_manual_changes']);
            
            $publish_timestamp = isset($update_settings['publish_timestamp']) ? sanitize_text_field($update_settings['publish_timestamp']) : null;

            $status = $data["status"] ?? '-1';
            
            $processSheetSyncParams = new ProcessSheetSyncParams(
                intval($lpagery_process_id), 
                $is_force_update,
                $is_overwrite_manual_changes, 
                $status,
                $publish_timestamp
            );
            
            
            wp_schedule_single_event(time(), 'lpagery_start_sync_for_process', array($processSheetSyncParams));
        }
        print_r(json_encode(array("success" => true,
            "process_id" => $lpagery_process_id)));
    } catch (\Throwable $exception) {
        print_r(json_encode(array("success" => false,
            "exception" => $exception->__toString())));
        wp_die();
    }
    wp_die();
}


function lpagery_extract_process_data($input_data, $process)
{
    check_ajax_referer('lpagery_ajax');
    if (!$input_data) {
        return null;
    }
    $taxonomy_terms = [];
    $taxonomy_terms_in_request = $input_data['taxonomy_terms'] ?? [];
    if (!empty($taxonomy_terms_in_request)) {

        // Filter and sanitize taxonomy terms to allow only numeric values
        foreach ($taxonomy_terms_in_request as $taxonomy => $terms) {
            $taxonomy_terms[$taxonomy] = array_map('intval', array_filter($terms, 'is_numeric'));
        }
    }


    $parent_path = (int)$input_data['parent_path'];
    $slug = sanitize_text_field($input_data['slug']);
    $status = sanitize_text_field($input_data['status']);

    if (isset($process)) {
        if ($status == "-1") {
            $unserialized_data = maybe_unserialize($process->data);
            $status = $unserialized_data['status'] ?? get_post_status($process->post_id);
        }
    }

    $data = array("taxonomy_terms" => $taxonomy_terms,
        "status" => $status,
        "parent_path" => $parent_path,
        "slug" => $slug);

    return $data;
}

add_action('wp_ajax_lpagery_get_duplicated_slugs', 'LPagery\lpagery_get_duplicated_slugs');
function lpagery_get_duplicated_slugs()
{
    check_ajax_referer('lpagery_ajax');
    try {
        $slug = isset($_POST['slug']) ? Utils::lpagery_sanitize_title_with_dashes($_POST['slug']) : null;
        $process_id = isset($_POST['process_id']) ? intval($_POST['process_id']) : -1;

        $data = $_POST['data'] ?? null;
        $template_id = intval($_POST['post_id']);

        $json_decode = json_decode(wp_unslash($_POST['keys']), true);
        $keys = isset($_POST['keys']) ? array_map('sanitize_text_field', $json_decode) : [];
        


        $duplicateSlugHandler = DuplicateSlugHandlerFactory::create();
        echo json_encode($duplicateSlugHandler->lpagery_get_duplicated_slugs($data, $template_id, $slug, $process_id, $keys));
    } catch (\Throwable $throwable) {
        echo json_encode(array("success" => false,
            "exception" => $throwable->__toString()));
    }
    wp_die();
}

add_action('wp_ajax_lpagery_download_post_json', 'LPagery\lpagery_download_post_json');
function lpagery_download_post_json()
{
    check_ajax_referer('lpagery_ajax');
    $process_id = intval($_GET["process_id"]);

    $pageExportHandler = PageExportHandler::get_instance();
    // Fetch the data to be exported
    $pageExportHandler->export($process_id);

    // Set the headers for file download

    exit;
}


add_action('wp_ajax_lpagery_get_users_for_settings', 'LPagery\lpagery_get_users_for_settings');
function lpagery_get_users_for_settings()
{
    check_ajax_referer('lpagery_ajax');
    $users = get_users();
    $mapped = array_map(function ($user) {
        return array("id" => $user->ID,
            "label" => $user->display_name . " (" . $user->user_email . ")");
    }, $users);

    print_r(json_encode($mapped));
    wp_die();
}


add_action('wp_ajax_lpagery_get_process_details', 'LPagery\lpagery_get_process_details');
function lpagery_get_process_details()
{
    check_ajax_referer('lpagery_ajax');

    $id = (int)$_POST['id'];
    $LPageryDao = LPageryDao::get_instance();
    $process = $LPageryDao->lpagery_get_process_by_id($id);
    $mapper = Mapper::get_instance();
    $result = $mapper->lpagery_map_process_update_details($process, array());
    print_r(json_encode($result));
    wp_die();
}


add_action('wp_ajax_lpagery_get_post', 'LPagery\lpagery_get_post');
function lpagery_get_post()
{
    check_ajax_referer('lpagery_ajax');

    $post_id = intval($_POST["post_id"]);

    $WP_Post = get_post($post_id);
    if (!$WP_Post) {
        echo json_encode(array("found" => false));
        wp_die();
    }
    $wpml_data = WpmlHelper::get_wpml_language_data($post_id);
    $array = array("title" => $WP_Post->post_title,
        "type" => $WP_Post->post_type,
        "found" => true,
        "permalink" => get_permalink($post_id));
    if ($wpml_data->language_code) {
        $array["language_code"] = $wpml_data->language_code;
    }

    $post_type_object = get_post_type_object($WP_Post->post_type);
    $array["hierarchical"] = $post_type_object && is_post_type_hierarchical($WP_Post->post_type);
    echo json_encode($array);
    wp_die();
}

add_action('wp_ajax_lpagery_get_google_sheet_scheduled_data', 'LPagery\lpagery_get_google_sheet_scheduled_data');

function lpagery_get_google_sheet_scheduled_data()
{
    // Ensure the function only runs for authenticated users
    check_ajax_referer('lpagery_ajax');

    $event = wp_get_scheduled_event('lpagery_sync_google_sheet');
    $response = [];

    if ($event) {
        // Next sync timestamp
        $response['next_sync_timestamp'] = $event->timestamp;

        // Schedule interval (e.g., hourly, daily)
        $response['schedule_interval'] = $event->schedule;

        // Time until the next sync in human-readable format (optional)
        if ($event->timestamp >= time()) {
            $response['time_until_next_sync'] = Utils::lpagery_time_ago($event->timestamp);
        }

        // Memory usage information (limit in bytes)
        $memory_usage = MemoryUtils::lpagery_get_memory_usage();
        $response['memory_limit_bytes'] = $memory_usage['limit'];
        $response['pretty_memory_limit'] = $memory_usage['pretty_limit']; // If you want the human-readable memory limit

    } else {
        wp_send_json_error('No scheduled sync event found.');
    }

    // Return only the raw values as JSON
    echo json_encode($response);
    wp_die();

}

add_action('wp_ajax_lpagery_create_onboarding_template_page', 'LPagery\lpagery_create_onboarding_template_page');
function lpagery_create_onboarding_template_page()
{
    check_ajax_referer('lpagery_ajax');
    $onboardingService = OnboardingService::get_instance();
    $page_id = $onboardingService->createOnboardingTemplatePage();
    if(!$page_id) {
        echo json_encode(array("success" => false));
        wp_die();
    }
    echo json_encode(array("success" => true, "page_id" => $page_id));
    wp_die();
}

add_action('wp_ajax_lpagery_assign_page_set_to_me', 'LPagery\lpagery_assign_page_set_to_me');
function lpagery_assign_page_set_to_me() {
    check_ajax_referer('lpagery_ajax');
    try {
        $process_id = isset($_POST['process_id']) ? (int)$_POST['process_id'] : null;
        if (!$process_id) {
            throw new \Exception('Process ID is required');
        }

        $LPageryDao = LPageryDao::get_instance();
        $process = $LPageryDao->lpagery_get_process_by_id($process_id);
        
        if (!$process) {
            throw new \Exception('Process not found');
        }

        $current_user_id = get_current_user_id();
        $LPageryDao->lpagery_update_process_user($process_id, $current_user_id);

        echo json_encode(array(
            "success" => true,
            "process_id" => $process_id
        ));
    } catch (\Throwable $exception) {
        echo json_encode(array(
            "success" => false,
            "exception" => $exception->getMessage()
        ));
    }
    wp_die();
}

