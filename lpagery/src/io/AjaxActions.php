<?php

namespace LPagery;

use Exception;
use LPagery\controller\DuplicatedSlugController;
use LPagery\controller\PostController;
use LPagery\controller\ProcessController;
use LPagery\controller\SlugController;
use LPagery\controller\TaxonomyController;
use LPagery\controller\UtilityController;
use LPagery\data\DbDeltaExecutor;
use LPagery\data\LPageryDao;
use LPagery\factories\CreatePostControllerFactory;
use LPagery\io\CreatePageDebugger;
use LPagery\io\suite\SuiteClient;
use LPagery\model\ProcessSheetSyncParams;
use LPagery\service\image_lookup\AttachmentBasenameService;
use LPagery\service\settings\SettingsController;
use LPagery\utils\Utils;

function lpagery_require_admin() {
    if (!current_user_can('manage_options')) {
        wp_send_json(array("success" => false, "exception" => 'You do not have permission to perform this action.'));
    }
}

function lpagery_require_editor() {
    if (!current_user_can('edit_pages')) {
        wp_send_json(array("success" => false, "exception" => 'You do not have permission to perform this action.'));
    }
}

add_action('wp_ajax_lpagery_sanitize_slug', 'LPagery\lpagery_sanitize_slug');

function lpagery_sanitize_slug()
{
    check_ajax_referer('lpagery_ajax');
    $parent_id = (int)$_POST['parent_id'];
    $template_id = (int)($_POST["template_id"] ?? 0);
    $slug = sanitize_text_field($_POST['slug'] ?? '');

    $slugController = SlugController::get_instance();
    $result = $slugController->sanitizeSlug($slug, $parent_id, $template_id);

    wp_send_json($result);
}

add_action('wp_ajax_lpagery_custom_sanitize_title', 'LPagery\lpagery_custom_sanitize_title');

function lpagery_custom_sanitize_title()
{
    check_ajax_referer('lpagery_ajax');
    $slug = sanitize_text_field($_POST['slug'] ?? '');

    $slugController = SlugController::get_instance();
    $sanitized_title = $slugController->customSanitizeTitle($slug);

    wp_send_json($sanitized_title);
}

add_action('wp_ajax_lpagery_create_posts', 'LPagery\lpagery_create_posts');

function lpagery_create_posts()
{
    global $wpdb;
    
    $nonce_validity = check_ajax_referer('lpagery_ajax');
    lpagery_require_editor();
    
    // Check if debug mode is enabled
    $debug_mode = isset($_POST['debug_mode']) && rest_sanitize_boolean($_POST['debug_mode']);
    $initial_query_count = 0;
    
    // Only enable query saving when debug mode is active
    if ($debug_mode && !defined('SAVEQUERIES')) {
        define('SAVEQUERIES', true);
    }
    
    $debugger = null;
    if ($debug_mode) {
        $initial_query_count = is_array($wpdb->queries) ? count($wpdb->queries) : 0;
        // Start hook profiling
        $debugger = CreatePageDebugger::get_instance();
        $debugger->start_hook_profiler();
    }

    // Start output buffering at the very beginning
    ob_start();

    // Get the current output buffer level to ensure we clean everything
    $initial_ob_level = ob_get_level();

    try {
        $createPostController = CreatePostControllerFactory::create();
        $result = $createPostController->lpagery_create_posts_ajax($_POST);
        // Capture any output that was generated
        $buffer_content = '';
        while (ob_get_level() > $initial_ob_level) {
            $buffer_content = ob_get_contents() . $buffer_content;
            ob_end_clean();
        }

        if (!empty($buffer_content)) {
            $result["buffer"] = $buffer_content;
        }
    } catch (\Throwable $exception) {
        // Capture ALL output including any HTML/CSS that leaked
        $buffer_content = '';
        while (ob_get_level() >= $initial_ob_level) {
            $current_buffer = ob_get_contents();
            if ($current_buffer !== false) {
                $buffer_content = $current_buffer . $buffer_content;
            }
            ob_end_clean();
            if (ob_get_level() < $initial_ob_level) {
                break;
            }
        }

        $result = array(
            "success" => false,
            "exception" => $exception->__toString(),
            "buffer" => $buffer_content,
            "trace" => $exception->getTraceAsString()
        );
    }

    // Collect debug info if debug mode is enabled
    if ($debug_mode && $debugger) {
        // Stop hook profiling before collecting results
        $debugger->stop_hook_profiler();
        
        $debug_info = $debugger->collect_database_queries($initial_query_count);
        $debug_info["slow_hooks"] = $debugger->collect_slow_hooks();
        $result["debug_queries"] = $debug_info;
    }

    if ($nonce_validity == 2) {
        $result["nonce"] = wp_create_nonce("lpagery_ajax");
    }

    // Clean any remaining output buffers to prevent HTML leakage
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Ensure clean JSON output
    wp_send_json($result);
}

add_action('wp_ajax_lpagery_get_settings', 'LPagery\lpagery_get_settings');
function lpagery_get_settings()
{
    check_ajax_referer('lpagery_ajax');
    $settings = SettingsController::get_instance()->getSettings();
    wp_send_json($settings);
}

add_action('wp_ajax_lpagery_get_batch_size', 'LPagery\lpagery_get_batch_size');
function lpagery_get_batch_size()
{
    check_ajax_referer('lpagery_ajax');
    $batch_size = SettingsController::get_instance()->getBatchSize();
    wp_send_json(array("batch_size" => $batch_size));
}

add_action('wp_ajax_lpagery_get_pages', 'LPagery\lpagery_get_pages');
function lpagery_get_pages()
{
    check_ajax_referer('lpagery_ajax');
    $custom_post_types = SettingsController::get_instance()->getEnabledCustomPostTypes();

    $mode = sanitize_text_field($_POST["mode"]);
    $select = sanitize_text_field($_POST["select"]);
    $template_id = null;
    if (array_key_exists("template_id", $_POST)) {
        $template_id = intval($_POST["template_id"]);
    }
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : "";

    $postController = PostController::get_instance();
    $mapped_posts = $postController->getPosts($search, $custom_post_types, $mode, $select, $template_id);

    wp_send_json($mapped_posts);
}


add_action('wp_ajax_lpagery_get_taxonomy_terms', 'LPagery\lpagery_get_taxonomy_terms');
function lpagery_get_taxonomy_terms()
{
    check_ajax_referer('lpagery_ajax');

    $taxonomyController = TaxonomyController::get_instance();
    $result = $taxonomyController->getTaxonomyTerms();

    wp_send_json($result);
}

add_action('wp_ajax_lpagery_get_taxonomies', 'LPagery\lpagery_get_taxonomies');
function lpagery_get_taxonomies()
{
    check_ajax_referer('lpagery_ajax');
    $post_type = isset($_POST["post_type"]) ? sanitize_text_field($_POST["post_type"]) : null;

    $taxonomyController = TaxonomyController::get_instance();
    $result = $taxonomyController->getTaxonomies($post_type);

    wp_send_json(array_values($result));
}


add_action('wp_ajax_lpagery_search_processes', 'LPagery\lpagery_search_processes');
function lpagery_search_processes()
{
    check_ajax_referer('lpagery_ajax');
    $post_id = (int)($_POST['post_id'] ?? null);
    $user_id = (int)(($_POST['user_id'] ?? null));
    $search_term = sanitize_text_field(urldecode($_POST['purpose'] ?? ""));
    $empty_filter = sanitize_text_field(urldecode($_POST['empty_filter'] ?? ""));

    $processController = ProcessController::get_instance();
    $processes = $processController->searchProcesses($post_id, $user_id, $search_term, $empty_filter);

    wp_send_json($processes);
}

add_action('wp_ajax_lpagery_get_ram_usage', 'LPagery\lpagery_get_ram_usage');
function lpagery_get_ram_usage()
{
    check_ajax_referer('lpagery_ajax');

    $utilityController = UtilityController::get_instance();
    $ram_usage = $utilityController->getRAMUsage();

    wp_send_json($ram_usage);
}


add_action('wp_ajax_lpagery_get_post_title_as_slug', 'LPagery\lpagery_get_post_title_as_slug');
function lpagery_get_post_title_as_slug()
{
    check_ajax_referer('lpagery_ajax');
    $post_id = (int)$_POST['post_id'];

    $slugController = SlugController::get_instance();
    $result = $slugController->getPostTitleAsSlug($post_id);

    wp_send_json($result);
}


add_action('wp_ajax_lpagery_get_users', 'LPagery\lpagery_get_users');
function lpagery_get_users()
{
    check_ajax_referer('lpagery_ajax');

    $utilityController = UtilityController::get_instance();
    $users = $utilityController->getUsersWithProcesses();

    wp_send_json($users);
}

add_action('wp_ajax_lpagery_get_template_posts', 'LPagery\lpagery_get_template_posts');
function lpagery_get_template_posts()
{
    check_ajax_referer('lpagery_ajax');

    $postController = PostController::get_instance();
    $template_posts = $postController->getTemplatePosts();

    wp_send_json($template_posts);
}

add_action('wp_ajax_lpagery_upsert_process', 'LPagery\lpagery_upsert_process');
function lpagery_upsert_process()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_editor();
    try {
        $processController = ProcessController::get_instance();
        $upsertParams = \LPagery\model\UpsertProcessParams::fromArray($_POST, "plugin");
        $result = $processController->upsertProcess($upsertParams);

        wp_send_json($result);
    } catch (\Throwable $exception) {
        wp_send_json(array("success" => false,
            "exception" => $exception->__toString()));
    }
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
        $parent_id = intval($_POST['parent_id'] ?? 0);
        $includeParentAsIdentifier = rest_sanitize_boolean($_POST["includeParentAsIdentifier"] ?? false);
        $json_decode = json_decode(wp_unslash($_POST['keys']), true);
        $keys = isset($_POST['keys']) ? array_map('sanitize_text_field', $json_decode) : [];

        $duplicatedSlugController = DuplicatedSlugController::get_instance();
        $result = $duplicatedSlugController->getDuplicatedSlugs($data, $template_id, $includeParentAsIdentifier,
            $parent_id, $slug, $process_id, $keys, true);

        wp_send_json($result);
    } catch (\Throwable $throwable) {
        wp_send_json(array("success" => false,
            "exception" => $throwable->__toString()));
    }
}

add_action('wp_ajax_lpagery_download_post_json', 'LPagery\lpagery_download_post_json');
function lpagery_download_post_json()
{
    check_ajax_referer('lpagery_ajax');
    $process_id = intval($_GET["process_id"]);

    $processController = ProcessController::get_instance();
    $processController->exportProcessJson($process_id);

    exit;
}


add_action('wp_ajax_lpagery_get_users_for_settings', 'LPagery\lpagery_get_users_for_settings');
function lpagery_get_users_for_settings()
{
    check_ajax_referer('lpagery_ajax');

    $utilityController = UtilityController::get_instance();
    $users = $utilityController->getUsersForSettings();

    wp_send_json($users);
}


add_action('wp_ajax_lpagery_get_process_details', 'LPagery\lpagery_get_process_details');
function lpagery_get_process_details()
{
    check_ajax_referer('lpagery_ajax');

    $id = (int)$_POST['id'];

    $processController = ProcessController::get_instance();
    $result = $processController->getProcessDetails($id);

    wp_send_json($result);
}


add_action('wp_ajax_lpagery_get_post', 'LPagery\lpagery_get_post');
function lpagery_get_post()
{
    check_ajax_referer('lpagery_ajax');

    $post_id = intval($_POST["post_id"]);

    $postController = PostController::get_instance();
    $post = $postController->getPost($post_id);

    wp_send_json($post);
}

add_action('wp_ajax_lpagery_get_google_sheet_scheduled_data', 'LPagery\lpagery_get_google_sheet_scheduled_data');

function lpagery_get_google_sheet_scheduled_data()
{
    check_ajax_referer('lpagery_ajax');

    $utilityController = UtilityController::get_instance();
    $response = $utilityController->getGoogleSheetScheduledData();

    wp_send_json((object)$response);
}

add_action('wp_ajax_lpagery_create_onboarding_template_page', 'LPagery\lpagery_create_onboarding_template_page');
function lpagery_create_onboarding_template_page()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_editor();

    $utilityController = UtilityController::get_instance();
    $result = $utilityController->createOnboardingTemplatePage();

    wp_send_json($result);
}

add_action('wp_ajax_lpagery_assign_page_set_to_me', 'LPagery\lpagery_assign_page_set_to_me');
function lpagery_assign_page_set_to_me()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_editor();
    try {
        $process_id = isset($_POST['process_id']) ? (int)$_POST['process_id'] : null;
        if (!$process_id) {
            throw new \Exception('Process ID is required');
        }

        $processController = ProcessController::get_instance();
        $result = $processController->assignPageSetToMe($process_id);

        wp_send_json($result);
    } catch (\Throwable $exception) {
        wp_send_json(array("success" => false,
            "exception" => $exception->getMessage()));
    }
}

add_action('wp_ajax_lpagery_reset_data', 'LPagery\lpagery_reset_data');
function lpagery_reset_data()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_admin();

    // Get delete_pages parameter
    $delete_pages = isset($_POST['delete_pages']) ? rest_sanitize_boolean($_POST['delete_pages']) : false;

    $processController = ProcessController::get_instance();
    $processController->resetData($delete_pages);

    wp_die();
}

add_action('wp_ajax_lpagery_update_process_managing_system', 'LPagery\lpagery_update_process_managing_system_ajax');
function lpagery_update_process_managing_system_ajax()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_editor();
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $LPageryDao = LPageryDao::get_instance();

    $suiteClient = SuiteClient::get_instance();


    try {
        $suiteClient->disconnect_page_set($id);
    } catch (\Throwable $throwable) {
        error_log("Error disconnecting page set: " . $throwable->getMessage());
    }

    $LPageryDao->lpagery_update_process_managing_system($id, "plugin");
    wp_send_json(["success" => true,
        "process_id" => $id]);
}

add_action('wp_ajax_lpagery_get_fresh_nonce', 'LPagery\lpagery_get_fresh_nonce');
function lpagery_get_fresh_nonce()
{

    check_ajax_referer('lpagery_ajax');
    $nonce = wp_create_nonce('lpagery_ajax');

    wp_send_json(['nonce' => $nonce]);
}


// App Tokens AJAX Actions
add_action('wp_ajax_lpagery_fetch_app_tokens', 'LPagery\lpagery_fetch_app_tokens_ajax');
function lpagery_fetch_app_tokens_ajax()
{
    check_ajax_referer('lpagery_ajax');

    try {


        $lpageryDao = LPageryDao::get_instance();
        $tokens = $lpageryDao->getAllAppTokens();


        wp_send_json($tokens);
    } catch (\Throwable $exception) {
        wp_send_json(array("success" => false,
            "exception" => $exception->getMessage(),
            "data" => []));
    }
}

add_action('wp_ajax_lpagery_revoke_app_token', 'LPagery\lpagery_revoke_app_token_ajax');
function lpagery_revoke_app_token_ajax()
{
    check_ajax_referer('lpagery_ajax');

    try {
        if (!current_user_can('manage_options')) {
            throw new Exception('You do not have permission to revoke app tokens.');
        }

        $token_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if (!$token_id) {
            throw new Exception('Invalid token ID.');
        }

        $lpageryDao = LPageryDao::get_instance();
        $success = $lpageryDao->deleteAppToken($token_id);

        if (!$success) {
            throw new Exception('Failed to revoke the token.');
        }

        wp_send_json(array("success" => true,
            "data" => ['id' => $token_id],
            "message" => 'Token revoked successfully.'));
    } catch (\Throwable $exception) {
        wp_send_json(array("success" => false,
            "exception" => $exception->getMessage(),
            "data" => []));
    }
}

add_action('wp_ajax_lpagery_trigger_look_sync', 'LPagery\lpagery_trigger_look_sync_ajax');
function lpagery_trigger_look_sync_ajax()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_editor();

    try {
        $page_set_id = isset($_POST['page_set_id']) ? intval($_POST['page_set_id']) : 0;
        $overwrite_manual_changes = isset($_POST['overwrite_manual_changes']) ? rest_sanitize_boolean($_POST['overwrite_manual_changes']) : null;

        error_log("Triggering look sync for page set ID: " . $overwrite_manual_changes);
        if (!$page_set_id) {
            throw new \Exception('Page set ID is required');
        }
        $lpageryDao = LPageryDao::get_instance();
        $process = $lpageryDao->lpagery_get_process_by_id($page_set_id);
        if($process->managing_system ==='app') {

            $lpageryDao->lpagery_update_process_sync_status($page_set_id, "PLANNED");
            $suiteClient = SuiteClient::get_instance();
            try {
                $result = $suiteClient->trigger_look_sync($page_set_id, $overwrite_manual_changes);
                wp_send_json(array("success" => true,
                    "data" => $result,
                    "message" => 'Look sync triggered successfully.'));
            } catch (\Throwable $exception) {
                throw new \Exception('Failed to trigger look sync: ' . $exception->getMessage());
            }
        } else {
            wp_schedule_single_event(time(), 'lpagery_start_sync_for_process', array(ProcessSheetSyncParams::processOnly($page_set_id, true)));
            wp_send_json(array("success" => true,
                "message" => 'Sync scheduled successfully.'));
        }

    } catch (\Throwable $exception) {
        wp_send_json(array("success" => false,
            "exception" => $exception->getMessage(),
            "data" => []));
    }
}

add_action('wp_ajax_repair_database_schema', 'LPagery\lpagery_repair_database_schema_ajax');
function lpagery_repair_database_schema_ajax()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_admin();

    $dbDeltaExecutor = new DbDeltaExecutor();
    $error = $dbDeltaExecutor->run();
    if($error) {
        wp_send_json(array("success" => false,
            "exception" => $error));
    } else {
        $rows_inserted = AttachmentBasenameService::get_instance()->backfill();
        
        wp_send_json(array("success" => true,
            "message" => "Database schema repaired successfully. Attachment basename index updated ({$rows_inserted} entries)."));
    }
}

add_action('wp_ajax_delete_lpagery_revisions', 'LPagery\lpagery_delete_revisions_ajax');
function lpagery_delete_revisions_ajax()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_admin();

    global $wpdb;
    
    try {
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        
        // Get all LPagery-generated post IDs
        $lpagery_post_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM $table_name_process_post"
        );
        
        if (empty($lpagery_post_ids)) {
            wp_send_json(array(
                "success" => true,
                "message" => "No LPagery-generated pages found. 0 revisions deleted."
            ));
        }
        
        // First, get the IDs of revisions we're about to delete
        // (so we can clean up their postmeta specifically, not all orphaned postmeta)
        $placeholders = implode(',', array_fill(0, count($lpagery_post_ids), '%d'));
        $revision_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts 
             WHERE post_type = 'revision' 
             AND post_parent IN ($placeholders)",
            ...$lpagery_post_ids
        ));
        
        // Delete the revisions
        $revisions_deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->posts 
             WHERE post_type = 'revision' 
             AND post_parent IN ($placeholders)",
            ...$lpagery_post_ids
        ));
        
        if ($revisions_deleted === false) {
            wp_send_json(array(
                "success" => false,
                "exception" => "Failed to delete revisions from database."
            ));
        }
        
        // Clean up postmeta only for the specific revisions we just deleted
        // This is much safer and faster than a broad orphaned postmeta cleanup
        if (!empty($revision_ids)) {
            $meta_placeholders = implode(',', array_fill(0, count($revision_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $wpdb->postmeta WHERE post_id IN ($meta_placeholders)",
                ...$revision_ids
            ));
        }
        
        $message = $revisions_deleted > 0 
            ? "Successfully deleted {$revisions_deleted} revision(s) from LPagery-generated pages."
            : "No revisions found for LPagery-generated pages.";
            
        wp_send_json(array(
            "success" => true,
            "message" => $message,
            "deleted_count" => $revisions_deleted
        ));
        
    } catch (Exception $e) {
        wp_send_json(array(
            "success" => false,
            "exception" => $e->getMessage()
        ));
    }
}

