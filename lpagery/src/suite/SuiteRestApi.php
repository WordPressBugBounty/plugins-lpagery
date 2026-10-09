<?php

namespace LPagery\suite;

use LPagery\io\Mapper;
use LPagery\service\settings\SettingsController;
use LPagery\service\preflight\PreflightRequest;

class SuiteRestApi
{
    public function __construct()
    {
        add_action('rest_api_init', [$this,
            'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route('lpagery/app/v1', '/token', ['methods' => 'POST',
            'callback' => [$this,
                'exchange_token'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/get_post', ['methods' => 'POST',
            'callback' => [$this,
                'get_post'],
            'permission_callback' => [$this, 'check_token_permission'],]);
        register_rest_route('lpagery/app/v1', '/get_pages', ['methods' => 'POST',
            'callback' => [$this,
                'get_pages'],
            'permission_callback' => [$this, 'check_token_permission'],]);
        register_rest_route('lpagery/app/v1', '/upsert_process', ['methods' => 'POST',
            'callback' => [$this,
                'upsert_process'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/sanitize_slug', ['methods' => 'POST',
            'callback' => [$this,
                'sanitize_slug'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/get_post_title_as_slug', ['methods' => 'POST',
            'callback' => [$this,
                'get_post_title_as_slug'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/get_taxonomies', ['methods' => 'POST',
            'callback' => [$this,
                'get_taxonomies'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/get_taxonomy_terms', ['methods' => 'POST',
            'callback' => [$this,
                'get_taxonomy_terms'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/get_process', ['methods' => 'POST',
            'callback' => [$this,
                'get_process'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/create_page', ['methods' => 'POST',
            'callback' => [$this,
                'create_page'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/disconnect_page_set', ['methods' => 'POST',
            'callback' => [$this,
                'disconnect_page_set'],
            'permission_callback' => [$this, 'check_token_permission'],]);


        register_rest_route('lpagery/app/v1', '/connect_page_set', ['methods' => 'POST',
            'callback' => [$this,
                'connect_page_set'],
            'permission_callback' => [$this, 'check_token_permission'],]);


        register_rest_route('lpagery/app/v1', '/search_processes', ['methods' => 'POST',
            'callback' => [$this,
                'search_processes'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/check_duplicated_slugs', ['methods' => 'POST',
            'callback' => [$this,
                'check_duplicated_slugs'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/delete_pages', ['methods' => 'POST',
            'callback' => [$this,
                'delete_pages'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/get_pages_for_update', ['methods' => 'POST',
            'callback' => [$this,
                'get_pages_for_update'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/get_pages_for_delete', ['methods' => 'POST',
            'callback' => [$this,
                'get_pages_for_delete'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/get_process_data', ['methods' => 'POST',
            'callback' => [$this,
                'get_process_data'],
            'permission_callback' => [$this, 'check_token_permission'],]);

    }

    /**
     * Permission callback that verifies the token
     */
    public function check_token_permission(\WP_REST_Request $request)
    {
        $tokenService = lpagery_root()->tokenValidator();
        return $tokenService->check_token_permission($request);
    }




    public function get_post(\WP_REST_Request $request)
    {
        $post_id = intval($request->get_json_params() ['post_id']);
        $post_controller = lpagery_root()->postController();
        $post_data = $post_controller->getPost($post_id);
        return rest_ensure_response($post_data);
    }

    public function get_pages(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $search = sanitize_text_field($json_params ['search'] ?? '');
        $mode = sanitize_text_field($json_params ['mode'] ?? '');
        $select = sanitize_text_field($json_params ['select'] ?? '');
        $template_id = intval($json_params ['template_id'] ?? 0);
        $post_controller = lpagery_root()->postController();
        $custom_post_types = lpagery_root()->settingsController()->getEnabledCustomPostTypes();
        $post_data = $post_controller->getPosts($search, $custom_post_types, $mode, $select, $template_id);
        return rest_ensure_response($post_data);
    }

    public function upsert_process(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $upsertParams = \LPagery\model\UpsertProcessParams::fromArray($json_params, "app");
        $result = lpagery_root()->processController()->upsertProcess($upsertParams);

        return rest_ensure_response($result);
    }

    public function sanitize_slug(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $parent_id = (int)$json_params['parent_id'];
        $template_id = (int)($json_params["template_id"] ?? 0);
        $slug = sanitize_text_field($json_params['slug'] ?? '');

        $slugController = lpagery_root()->slugController();
        $result = $slugController->sanitizeSlug($slug, $parent_id, $template_id);


        return rest_ensure_response($result);
    }

    public function get_post_title_as_slug(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $post_id = (int)$json_params['post_id'];


        $slugController = lpagery_root()->slugController();
        $result = $slugController->getPostTitleAsSlug($post_id);


        return rest_ensure_response($result);
    }

    public function get_taxonomies(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $post_type = sanitize_text_field($json_params['post_type']);

        $result = lpagery_root()->taxonomyController()->getTaxonomies($post_type);

        return rest_ensure_response($result);
    }

    public function get_taxonomy_terms(\WP_REST_Request $request)
    {

        $result = lpagery_root()->taxonomyController()->getTaxonomyTerms();

        return rest_ensure_response($result);
    }


    public function get_process(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();

        $id = intval($json_params['id']);
        $result = lpagery_root()->processController()->getProcessDetails($id);

        return rest_ensure_response($result);
    }

    public function disconnect_page_set(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();

        $id = intval($json_params['id']);
        lpagery_root()->processController()->updateManagingSystem($id, "plugin");

        return rest_ensure_response(["success" => true]);
    }

    public function connect_page_set(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();

        $id = intval($json_params['id']);
        $process = lpagery_root()->processController()->updateManagingSystem($id, "app");

        $data = maybe_unserialize($process->data);
        return rest_ensure_response(["success" => true,
            "name" => $process->purpose ?? '',
            "slug_pattern" => $data["slug"]]);
    }

    public function search_processes(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $search = sanitize_text_field($json_params['search']);

        $user = wp_get_current_user();
        if(!$user || !$user->ID) {
            return rest_ensure_response([]);
        }
        $processes = lpagery_root()->processController()->searchProcesses(null, $user->ID, $search, "", "plugin");

        return rest_ensure_response($processes);
    }

    /**
     * The Pre-flight Check for the Suite. It returns the same report as the dashboard's AJAX
     * endpoint. A Suite that still sends its rows as `data` (a list of rows without ids) gets them
     * numbered from 1 in order.
     */
    public function check_duplicated_slugs(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        try {
            if (!isset($json_params['rows']) && isset($json_params['data']) && is_array($json_params['data'])) {
                $json_params['rows'] = array_map(function ($row, $index) {
                    return array('row_id' => $index + 1, 'data' => $row);
                }, array_values($json_params['data']), array_keys(array_values($json_params['data'])));
            }
            $preflight_request = PreflightRequest::fromArray($json_params);
            $result = lpagery_root()->preflightController()->runPreflightCheck($preflight_request);

            return rest_ensure_response($result);
        } catch (\Throwable $throwable) {
            error_log($throwable->__toString());
            return rest_ensure_response(array("success" => false,
                "exception" => $throwable->__toString()));
        }
    }

    public function create_page(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        ob_start();

        try {
            $createPostController = lpagery_root()->createPostController();
            $index = intval($json_params["index"]);
            if ($index == 0) {
                $process_id = (int)$json_params['process_id'];
                lpagery_root()->syncQueueRepository()->update_process_sync_status($process_id, "RUNNING");
            }
            $result = $createPostController->lpagery_create_posts_ajax($json_params);
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


        return rest_ensure_response($result);
    }

    public function delete_pages(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $slugs = $json_params['slugs'];
        $process_id = intval($json_params['process_id']);
        $sanitized_slugs = array_map('sanitize_text_field', $slugs);

        $result = lpagery_root()->generatedPageRepository()->get_process_posts_slugs($process_id);
        $post_ids = [];
        foreach ($result as $post_slug_entry) {
            if (!$post_slug_entry->client_generated_slug || in_array($post_slug_entry->client_generated_slug,
                    $sanitized_slugs)) {
                continue;
            }
            $post_ids[] = $post_slug_entry->post_id;
        }
        $skipped_template_ids = [];
        if (!empty($post_ids)) {
            $skipped_template_ids = lpagery_root()->deletePageService()->deletePages($post_ids);
            if (!empty($skipped_template_ids)) {
                // A Generated Page of this set can be the Template Page of another, live set, and the
                // delete guard keeps it (ADR 0017). The Suite client reads the count from the response;
                // the log line is for the site owner, who never sees that client.
                error_log(sprintf(
                    'LPagery: kept page(s) %s of page set %s while deleting from the Suite, they are template pages for Live Mode pages.',
                    implode(', ', $skipped_template_ids),
                    (string)$process_id
                ));
            }
        }

        return rest_ensure_response([
            "success" => true,
            "skipped_template_count" => count($skipped_template_ids),
            "skipped_template_ids" => array_values($skipped_template_ids),
        ]);
    }

    public function get_pages_for_delete(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $slugs = $json_params['slugs'];
        $process_id = intval($json_params['process_id']);
        $sanitized_slugs = array_map('sanitize_text_field', $slugs);

        $result = lpagery_root()->generatedPageRepository()->get_process_posts_slugs($process_id);
        $posts = [];
        foreach ($result as $post_slug_entry) {
            if (!$post_slug_entry->client_generated_slug || in_array($post_slug_entry->client_generated_slug,
                    $sanitized_slugs)) {
                continue;
            }
            $post = get_post($post_slug_entry->post_id);
            $posts[] = array("post_title" => $post->post_title,
                "url" => get_permalink($post->ID),
                "id" => $post->ID,
                "slug" => $post->post_name,
                "post_type" => $post->post_type);
        }


        return rest_ensure_response($posts);
    }


    function get_pages_for_update(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();

        $process_id = (intval($json_params["process_id"] ?? 0));

        $posts = lpagery_root()->generatedPageRepository()->get_existing_posts_for_update_modal(null, $process_id);
        $mapper = lpagery_root()->mapper();
        $mapped = array_map([$mapper,
            'lpagery_map_post_for_update_modal'], $posts);
        return rest_ensure_response($mapped);
    }
    function get_process_data(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();

        $process_id = (intval($json_params["process_id"] ?? 0));

        $processes = lpagery_root()->generatedPageRepository()->get_process_post_input_data($process_id);


        $data = array_map(function ($process) {
            $unserialized = maybe_unserialize($process->data ?? '');
            // Replace null values with empty strings
            if (is_array($unserialized)) {
                return array_map(function($value) {
                    return $value === null ? '' : $value;
                }, $unserialized);
            }
            return $unserialized;
        }, $processes);

        // Extract headers (column names) from the data
        $headers = [];
        if (!empty($data)) {
            foreach ($data as $row) {
                if (is_array($row)) {
                    $row_keys = array_keys($row);
                    $headers = array_merge($headers, is_array($row_keys) ? $row_keys : []);
                }
            }
            $headers = array_unique($headers);
            $headers = array_values($headers); // Re-index array
        }


        return rest_ensure_response(array("success" => true,
            "data" => ($data),
            "headers" => $headers));
    }

    public function exchange_token(\WP_REST_Request $request)
    {

        $code = $request->get_param('code');
        if (!$code) {
            return new \WP_Error('no_code', 'Missing authorization code', ['status' => 400]);
        }

        $transient_key = 'suite_oauth_code_' . $code;
        $auth_data = get_transient($transient_key);
        if (!$auth_data) {
            return new \WP_Error('invalid_code', 'Invalid or expired code', ['status' => 400]);
        }

        $nonce = $auth_data["nonce"];
        $nonce_from_request = $request->get_header('X-LPagery-WP-Nonce');
        if (!$nonce_from_request || $nonce !== $nonce_from_request) {
            return new \WP_Error('invalid_nonce', 'Invalid nonce', ['status' => 403]);
        }
        $user_id = intval($auth_data["user_id"]);
        $app_user_mail_address = sanitize_email($auth_data["app_user_mail_address"]);


        // Generate a secure random token
        $token = wp_generate_password(32, false);

        // Store the hashed token
        $this->store_token($user_id, $token, $app_user_mail_address);

        delete_transient($transient_key);

        return rest_ensure_response(['token' => $token,]);
    }
    private function store_token($user_id, $token, $app_user_mail_address)
    {
        // Use SHA-256 for API tokens instead of bcrypt - much faster for high-entropy tokens
        $hashed_token = hash('sha256', $token);

        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';
        $wpdb->insert($table_name, ['user_id' => $user_id,
            'token' => $hashed_token,
            'app_user_mail_address' => $app_user_mail_address,]);
    }

}