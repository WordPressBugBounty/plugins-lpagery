<?php

namespace LPagery;

use Exception;
use LPagery\data\DbDeltaExecutor;
use LPagery\io\AjaxEndpoint;
use LPagery\io\Cap;
use LPagery\io\CreatePageDebugger;
use LPagery\model\TrackingPermissions;
use LPagery\service\image_lookup\AttachmentBasenameService;
use LPagery\service\preflight\PreflightRequest;
use LPagery\service\settings\Settings;
use LPagery\service\save_page\update\RenderModeBatchSwitcher;
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

AjaxEndpoint::register('lpagery_sanitize_slug', Cap::none(), 'LPagery\lpagery_sanitize_slug');

function lpagery_sanitize_slug()
{
    $parent_id = (int)$_POST['parent_id'];
    $template_id = (int)($_POST["template_id"] ?? 0);
    $slug = sanitize_text_field($_POST['slug'] ?? '');

    $slugController = lpagery_root()->slugController();
    $result = $slugController->sanitizeSlug($slug, $parent_id, $template_id);

    return $result;
}

AjaxEndpoint::register('lpagery_custom_sanitize_title', Cap::none(), 'LPagery\lpagery_custom_sanitize_title');

function lpagery_custom_sanitize_title()
{
    $slug = sanitize_text_field($_POST['slug'] ?? '');

    $slugController = lpagery_root()->slugController();
    $sanitized_title = $slugController->customSanitizeTitle($slug);

    return $sanitized_title;
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
        $debugger = lpagery_root()->createPageDebugger();
        $debugger->start_hook_profiler();
    }

    // Start output buffering at the very beginning
    ob_start();

    // Get the current output buffer level to ensure we clean everything
    $initial_ob_level = ob_get_level();

    try {
        $createPostController = lpagery_root()->createPostController();
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

AjaxEndpoint::register('lpagery_get_settings', Cap::none(), 'LPagery\lpagery_get_settings');
function lpagery_get_settings()
{
    $settings = lpagery_root()->settingsController()->getSettings();
    return $settings;
}

// On-demand Edge Cache Probe run (issue #233): the settings screen's "run detection" action. Bypasses the
// probe's dormancy gates to refresh the provenance verdict, but never overwrites a decided setting value
// (see EdgeCacheProbe::run_manual()). Free code — the probe decides a serve-path default.
AjaxEndpoint::register('lpagery_run_edge_cache_probe', Cap::admin(), 'LPagery\lpagery_run_edge_cache_probe');
function lpagery_run_edge_cache_probe()
{
    $verdict = lpagery_root()->edgeCacheProbe()->run_manual();
    return array("conclusive" => $verdict !== null);
}

AjaxEndpoint::register('lpagery_get_batch_size', Cap::none(), 'LPagery\lpagery_get_batch_size');
function lpagery_get_batch_size()
{
    $batch_size = lpagery_root()->settingsController()->getBatchSize();
    return array("batch_size" => $batch_size);
}

AjaxEndpoint::register('lpagery_get_pages', Cap::none(), 'LPagery\lpagery_get_pages');
function lpagery_get_pages()
{
    $custom_post_types = lpagery_root()->settingsController()->getEnabledCustomPostTypes();

    $mode = sanitize_text_field($_POST["mode"]);
    $select = sanitize_text_field($_POST["select"]);
    $template_id = null;
    if (array_key_exists("template_id", $_POST)) {
        $template_id = intval($_POST["template_id"]);
    }
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : "";

    $postController = lpagery_root()->postController();
    $mapped_posts = $postController->getPosts($search, $custom_post_types, $mode, $select, $template_id);

    return $mapped_posts;
}


AjaxEndpoint::register('lpagery_get_taxonomy_terms', Cap::none(), 'LPagery\lpagery_get_taxonomy_terms');
function lpagery_get_taxonomy_terms()
{
    $taxonomyController = lpagery_root()->taxonomyController();
    $result = $taxonomyController->getTaxonomyTerms();

    return $result;
}

AjaxEndpoint::register('lpagery_get_taxonomies', Cap::none(), 'LPagery\lpagery_get_taxonomies');
function lpagery_get_taxonomies()
{
    $post_type = isset($_POST["post_type"]) ? sanitize_text_field($_POST["post_type"]) : null;

    $taxonomyController = lpagery_root()->taxonomyController();
    $result = $taxonomyController->getTaxonomies($post_type);

    return array_values($result);
}


AjaxEndpoint::register('lpagery_search_processes', Cap::none(), 'LPagery\lpagery_search_processes');
function lpagery_search_processes()
{
    $post_id = (int)($_POST['post_id'] ?? null);
    $user_id = (int)(($_POST['user_id'] ?? null));
    $search_term = sanitize_text_field(urldecode($_POST['purpose'] ?? ""));
    $empty_filter = sanitize_text_field(urldecode($_POST['empty_filter'] ?? ""));

    $processController = lpagery_root()->processController();
    $processes = $processController->searchProcesses($post_id, $user_id, $search_term, $empty_filter);

    return $processes;
}


// The Overview snapshot (issue #269): one read that answers what LPagery has built on this site —
// page totals with their status and Render Mode splits, the Page Set total, the health block, the
// twelve monthly buckets and the most recent Page Sets. Computed live per request, no caching, so
// the tab always agrees with Manage. Free on every tier; admin-gated because it reports the whole
// site's inventory.
AjaxEndpoint::register('lpagery_get_overview_snapshot', Cap::admin(), 'LPagery\lpagery_get_overview_snapshot');
function lpagery_get_overview_snapshot()
{
    return lpagery_root()->overviewController()->getSnapshot();
}

AjaxEndpoint::register('lpagery_get_ram_usage', Cap::none(), 'LPagery\lpagery_get_ram_usage');
function lpagery_get_ram_usage()
{
    $utilityController = lpagery_root()->utilityController();
    $ram_usage = $utilityController->getRAMUsage();

    return $ram_usage;
}


AjaxEndpoint::register('lpagery_get_post_title_as_slug', Cap::none(), 'LPagery\lpagery_get_post_title_as_slug');
function lpagery_get_post_title_as_slug()
{
    $post_id = (int)$_POST['post_id'];

    $slugController = lpagery_root()->slugController();
    $result = $slugController->getPostTitleAsSlug($post_id);

    return $result;
}


AjaxEndpoint::register('lpagery_get_users', Cap::none(), 'LPagery\lpagery_get_users');
function lpagery_get_users()
{
    $utilityController = lpagery_root()->utilityController();
    $users = $utilityController->getUsersWithProcesses();

    return $users;
}

AjaxEndpoint::register('lpagery_get_template_posts', Cap::none(), 'LPagery\lpagery_get_template_posts');
function lpagery_get_template_posts()
{
    $postController = lpagery_root()->postController();
    $template_posts = $postController->getTemplatePosts();

    return $template_posts;
}

AjaxEndpoint::register('lpagery_get_live_mode_support', Cap::editor(), 'LPagery\lpagery_get_live_mode_support');
function lpagery_get_live_mode_support()
{
    $template_id = intval($_POST['template_id'] ?? 0);
    $builderSupport = lpagery_root()->liveBuilderSupport();

    return array(
        "supported" => $builderSupport->is_supported($template_id),
        "builder" => $builderSupport->detect_builder($template_id),
    );
}

// Beta render-error telemetry drain (Phase 9). PostHog capture is frontend-only in this plugin, but
// live-render errors happen on anonymous visitor requests; the render path records them server-side
// (consent- and throttle-gated) and the dashboard drains + reports them to PostHog once on load.
AjaxEndpoint::register('lpagery_get_live_render_errors', Cap::editor(), 'LPagery\lpagery_get_live_render_errors');
function lpagery_get_live_render_errors()
{
    $events = lpagery_root()->liveRenderTelemetry()->consume_events();
    return array("success" => true, "events" => $events);
}

AjaxEndpoint::register('lpagery_upsert_process', Cap::editor(), 'LPagery\lpagery_upsert_process');
function lpagery_upsert_process()
{
    $processController = lpagery_root()->processController();
    $upsertParams = \LPagery\model\UpsertProcessParams::fromArray($_POST, "plugin");
    $result = $processController->upsertProcess($upsertParams);

    return $result;
}

AjaxEndpoint::register('lpagery_run_preflight_check', Cap::editor(), 'LPagery\lpagery_run_preflight_check');
function lpagery_run_preflight_check()
{
    $request = PreflightRequest::fromArray($_POST);

    return lpagery_root()->preflightController()->runPreflightCheck($request);
}

add_action('wp_ajax_lpagery_download_post_json', 'LPagery\lpagery_download_post_json');
function lpagery_download_post_json()
{
    check_ajax_referer('lpagery_ajax');
    $process_id = intval($_GET["process_id"]);

    $processController = lpagery_root()->processController();
    $processController->exportProcessJson($process_id);

    exit;
}


AjaxEndpoint::register('lpagery_get_users_for_settings', Cap::none(), 'LPagery\lpagery_get_users_for_settings');
function lpagery_get_users_for_settings()
{
    $utilityController = lpagery_root()->utilityController();
    $users = $utilityController->getUsersForSettings();

    return $users;
}


AjaxEndpoint::register('lpagery_get_process_details', Cap::none(), 'LPagery\lpagery_get_process_details');
function lpagery_get_process_details()
{
    $id = (int)$_POST['id'];

    $processController = lpagery_root()->processController();
    $result = $processController->getProcessDetails($id);

    return $result;
}


AjaxEndpoint::register('lpagery_get_post', Cap::none(), 'LPagery\lpagery_get_post');
function lpagery_get_post()
{
    $post_id = intval($_POST["post_id"]);

    $postController = lpagery_root()->postController();
    $post = $postController->getPost($post_id);

    return $post;
}


AjaxEndpoint::register('lpagery_create_onboarding_template_page', Cap::editor(), 'LPagery\lpagery_create_onboarding_template_page');
function lpagery_create_onboarding_template_page()
{
    $utilityController = lpagery_root()->utilityController();
    $result = $utilityController->createOnboardingTemplatePage();

    return $result;
}

AjaxEndpoint::register('lpagery_assign_page_set_to_me', Cap::editor(), 'LPagery\lpagery_assign_page_set_to_me');
function lpagery_assign_page_set_to_me()
{
    $process_id = isset($_POST['process_id']) ? (int)$_POST['process_id'] : null;
    if (!$process_id) {
        throw new \Exception('Process ID is required');
    }

    $processController = lpagery_root()->processController();
    $result = $processController->assignPageSetToMe($process_id);

    return $result;
}

add_action('wp_ajax_lpagery_reset_data', 'LPagery\lpagery_reset_data');
function lpagery_reset_data()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_admin();

    // Get delete_pages parameter
    $delete_pages = isset($_POST['delete_pages']) ? rest_sanitize_boolean($_POST['delete_pages']) : false;

    $processController = lpagery_root()->processController();
    $processController->resetData($delete_pages);

    wp_die();
}

AjaxEndpoint::register('lpagery_update_process_managing_system', Cap::editor(), 'LPagery\lpagery_update_process_managing_system_ajax');
function lpagery_update_process_managing_system_ajax()
{
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    $suiteClient = lpagery_root()->suiteClient();


    try {
        $suiteClient->disconnect_page_set($id);
    } catch (\Throwable $throwable) {
        error_log("Error disconnecting page set: " . $throwable->getMessage());
    }

    lpagery_root()->pageSetRepository()->update_process_managing_system($id, "plugin");
    return ["success" => true,
        "process_id" => $id];
}

// Every View across all Page Sets, for the global Views page. Deliberately FREE and only
// editor-gated (NOT lpagery_require_views_pro): reading the Views list stays available after a
// downgrade so saved Views remain discoverable, while create/edit/delete keep their Pro gates in
// the premium file (ADR-0006 / ADR-0002). This handler lives in the free AjaxActions.php so it
// still exists once Freemius strips the premium file from the free build.
AjaxEndpoint::register('lpagery_get_all_views', Cap::editor(), 'LPagery\lpagery_get_all_views');
function lpagery_get_all_views()
{
    $views = lpagery_root()->viewController()->getAllViews();
    return array("success" => true, "views" => $views);
}

AjaxEndpoint::register('lpagery_get_fresh_nonce', Cap::none(), 'LPagery\lpagery_get_fresh_nonce');
function lpagery_get_fresh_nonce()
{
    $nonce = wp_create_nonce('lpagery_ajax');

    return ['nonce' => $nonce];
}


// App Tokens AJAX Actions
AjaxEndpoint::register('lpagery_fetch_app_tokens', Cap::none(), 'LPagery\lpagery_fetch_app_tokens_ajax');
function lpagery_fetch_app_tokens_ajax()
{
    $tokens = lpagery_root()->appTokenRepository()->getAllAppTokens();

    return $tokens;
}

AjaxEndpoint::register('lpagery_revoke_app_token', Cap::none(), 'LPagery\lpagery_revoke_app_token_ajax');
function lpagery_revoke_app_token_ajax()
{
    if (!current_user_can('manage_options')) {
        throw new Exception('You do not have permission to revoke app tokens.');
    }

    $token_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if (!$token_id) {
        throw new Exception('Invalid token ID.');
    }

    $success = lpagery_root()->appTokenRepository()->deleteAppToken($token_id);

    if (!$success) {
        throw new Exception('Failed to revoke the token.');
    }

    return array("success" => true,
        "data" => ['id' => $token_id],
        "message" => 'Token revoked successfully.');
}


AjaxEndpoint::register('repair_database_schema', Cap::admin(), 'LPagery\lpagery_repair_database_schema_ajax');
function lpagery_repair_database_schema_ajax()
{
    $dbDeltaExecutor = new DbDeltaExecutor();
    $error = $dbDeltaExecutor->run();
    if($error) {
        return array("success" => false,
            "exception" => $error);
    } else {
        $rows_inserted = lpagery_root()->attachmentBasenameService()->backfill();

        return array("success" => true,
            "message" => "Database schema repaired successfully. Attachment basename index updated ({$rows_inserted} entries).");
    }
}

AjaxEndpoint::register('delete_lpagery_revisions', Cap::admin(), 'LPagery\lpagery_delete_revisions_ajax');
function lpagery_delete_revisions_ajax()
{
    try {
        $deleted_count = lpagery_root()->generatedPageRepository()->delete_revisions_for_generated_pages();

        $message = $deleted_count > 0
            ? "Successfully deleted {$deleted_count} revision(s) from LPagery-generated pages."
            : "No revisions found for LPagery-generated pages.";

        return array(
            "success" => true,
            "message" => $message,
            "deleted_count" => $deleted_count
        );

    } catch (Exception $e) {
        return array(
            "success" => false,
            "exception" => $e->getMessage()
        );
    }
}

// Settings and tracking permissions are free on every tier: the Settings tab is reachable without a
// license (the paid rows inside it are locked individually in the UI) and the tracking consent form has
// no plan gate at all, so both writes have to exist in the free build. Nonce + editor capability.
add_action('wp_ajax_lpagery_save_settings', 'LPagery\lpagery_save_settings');
function lpagery_save_settings()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_editor();

    // Sanitize incoming settings data
    $settings_data = Utils::lpagery_sanitize_object($_POST);
    $settings = new Settings();

    // Populate the settings object with sanitized data
    $settings->spintax = rest_sanitize_boolean($settings_data['spintax']);
    $settings->google_sheet_sync_force_update = rest_sanitize_boolean($settings_data['google_sheet_sync_force_update']);
    $settings->google_sheet_sync_overwrite_manual_changes = rest_sanitize_boolean($settings_data['google_sheet_sync_overwrite_manual_changes']);
    $settings->image_processing = rest_sanitize_boolean($settings_data['image_processing']);
    $settings->image_partial_match = rest_sanitize_boolean($settings_data['image_partial_match'] ?? true);
    $settings->author_id = intval($settings_data['author_id']);
    $settings->google_sheet_sync_interval = sanitize_text_field($settings_data['google_sheet_sync_interval']);
    $settings->hierarchical_taxonomy_handling = sanitize_text_field($settings_data['hierarchical_taxonomy_handling']);
    $settings->google_sheet_sync_enabled = rest_sanitize_boolean($settings_data['google_sheet_sync_enabled']);
    $settings->sync_batch_size = intval($settings_data['sync_batch_size']);
    $settings->hide_generated_pages = rest_sanitize_boolean($settings_data['hide_generated_pages'] ?? false);
    $settings->default_render_mode = sanitize_text_field($settings_data['default_render_mode'] ?? 'classic');
    $settings->default_background_generation = rest_sanitize_boolean($settings_data['default_background_generation'] ?? false);
    // Site-wide Virtual Image URLs setting (ADR 0015). Default on when absent so a client that omits the
    // field never silently flips live pages into Source URL Fallback.
    $settings->virtual_images_enabled = rest_sanitize_boolean($settings_data['virtual_images_enabled'] ?? true);

    // Validate Google Sheet sync interval
    $schedules = array_keys(wp_get_schedules());
    if (!in_array($settings->google_sheet_sync_interval, $schedules)) {
        $settings->google_sheet_sync_interval = 'daily';
    }

    // Sanitize custom post types
    $settings->custom_post_types = is_array($settings_data['custom_post_types'] ?? null)
        ? array_map('sanitize_text_field', $settings_data['custom_post_types'])
        : array();

    // Custom post types and hiding Generated Pages from the WordPress lists are Extended-tier. The UI
    // locks both rows below Extended, but this endpoint is free, so the server keeps whatever is stored
    // rather than trusting the payload. The gate is written out of non-suffixed calls so it survives
    // Freemius' strip, and includes `is_premium()` because a free build's site can still carry a paid
    // plan (a licence activated before the premium zip is installed).
    if (!(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial('extended'))) {
        $settingsController = lpagery_root()->settingsController();
        // getSettings() masks custom post types on limited plans, so read the persisted value directly.
        $settings->custom_post_types = $settingsController->getStoredCustomPostTypes();
        $settings->hide_generated_pages = $settingsController->getSettings()->hide_generated_pages;
    }

    // Parse next Google Sheet sync time if provided
    if (isset($settings_data["next_google_sheet_sync"])) {
        $settings->next_google_sheet_sync = strval(strtotime(get_gmt_from_date($settings_data["next_google_sheet_sync"])));
    } else {
        $settings->next_google_sheet_sync = null;
    }

    // Save settings through the controller
    lpagery_root()->settingsController()->saveSettings($settings);

    wp_die();
}

add_action('wp_ajax_lpagery_save_tracking_permissions', 'LPagery\lpagery_save_tracking_permissions');
function lpagery_save_tracking_permissions()
{
    check_ajax_referer('lpagery_ajax');
    lpagery_require_editor();
    $sentry = filter_var($_POST["sentry"], FILTER_VALIDATE_BOOLEAN);
    $posthog = filter_var($_POST["posthog"], FILTER_VALIDATE_BOOLEAN);
    $intercom = filter_var($_POST["intercom"], FILTER_VALIDATE_BOOLEAN);
    lpagery_root()->trackingPermissionService()->savePermissions(new TrackingPermissions($sentry,
        $posthog, $intercom));

    wp_die();

}

// Render Mode conversion (ADR 0019). Materialize (Live to Classic) runs on every tier, so both
// endpoints are free and carry the nonce + editor capability check like every other free action. The
// controller routes by direction and refuses Strip to Stub (Classic to Live) below Extended.
AjaxEndpoint::register('lpagery_convert_page_render_mode', Cap::editor(), 'LPagery\lpagery_convert_page_render_mode');
function lpagery_convert_page_render_mode()
{
    $post_id = (int)($_POST['post_id'] ?? 0);
    $direction = sanitize_text_field($_POST['direction'] ?? 'classic');

    $result = lpagery_root()->liveModeConversionController()->convert_page($post_id, $direction);

    return array("success" => true) + $result;
}

AjaxEndpoint::register('lpagery_switch_process_render_mode', Cap::editor(), 'LPagery\lpagery_switch_process_render_mode');
function lpagery_switch_process_render_mode()
{
    $process_id = (int)($_POST['process_id'] ?? 0);
    $target_type = sanitize_text_field($_POST['target_type'] ?? '');
    $limit = min((int)($_POST['limit'] ?? RenderModeBatchSwitcher::DEFAULT_BATCH_SIZE),
        RenderModeBatchSwitcher::MAX_BATCH_SIZE);

    $result = lpagery_root()->liveModeConversionController()->switch_process($process_id, $target_type, $limit);

    return array("success" => true) + $result;
}
