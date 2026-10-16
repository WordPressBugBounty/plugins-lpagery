<?php

namespace LPagery\suite;

use LPagery\io\Mapper;
use LPagery\service\lookup\LookupService;
use LPagery\service\settings\SettingsController;
use LPagery\service\preflight\PreflightRequest;
use LPagery\service\queue\BackgroundOperationInProgressException;

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

        register_rest_route('lpagery/app/v1', '/abort_run', ['methods' => 'POST',
            'callback' => [$this,
                'abort_run'],
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

        register_rest_route('lpagery/app/v1', '/get_users', ['methods' => 'POST',
            'callback' => [$this,
                'get_users'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/resolve_values', ['methods' => 'POST',
            'callback' => [$this,
                'resolve_values'],
            'permission_callback' => [$this, 'check_token_permission'],]);

        register_rest_route('lpagery/app/v1', '/search_media', ['methods' => 'POST',
            'callback' => [$this,
                'search_media'],
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

    /**
     * Without lookup params, every taxonomy's terms as before. With them, the term picker: one
     * taxonomy's terms with their `Parent>Child` paths, searched and paged.
     */
    public function get_taxonomy_terms(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params() ?: [];
        if (empty(array_intersect(['taxonomy', 'search', 'page', 'per_page', 'language'], array_keys($json_params)))) {
            $result = lpagery_root()->taxonomyController()->getTaxonomyTerms();

            return rest_ensure_response($result);
        }

        [$page, $per_page] = $this->lookup_paging($json_params);
        try {
            $result = lpagery_root()->lookupService()->search_terms(
                $this->lookup_context($json_params),
                sanitize_text_field($json_params['search'] ?? ''),
                $page,
                $per_page
            );
        } catch (\InvalidArgumentException $exception) {
            return new \WP_Error('lpagery_invalid_lookup', $exception->getMessage(), ['status' => 400]);
        }
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
            $process_id = (int)$json_params['process_id'];
            $creation_id = sanitize_text_field((string)($json_params['creation_id'] ?? ''));
            // The App Run takes the Page Set on its first page and holds it until its last (#305), so
            // later pages skip the per-set lock that the run's own RUNNING status would trip. A retried
            // first page of the same run is let back in; any other run on a busy set is rejected. Later
            // pages are accepted only from the run holding the set, and every accepted page records the
            // run's last activity, so a run that goes silent is Abandoned (#309).
            if ($index == 0) {
                if (!lpagery_root()->syncQueueRepository()->try_acquire_app_run($process_id, $creation_id)) {
                    throw new BackgroundOperationInProgressException();
                }
            } elseif (!lpagery_root()->syncQueueRepository()->touch_app_run($process_id, $creation_id)) {
                throw new AppRunNotHoldingSetException();
            }
            $result = $createPostController->create_posts_holding_lock($json_params);
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

    /**
     * The App aborts its own App Run (issue #309): when `creation_id` is the run holding the Page Set,
     * the set is released and its sync status becomes ERROR with the abort message. Any other call (an
     * unknown, finished or superseded run) changes nothing and still succeeds, so the App may retry it;
     * `aborted` says whether this call ended the run.
     */
    public function abort_run(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params();
        $process_id = (int)($json_params['process_id'] ?? 0);
        $creation_id = sanitize_text_field((string)($json_params['creation_id'] ?? ''));

        $aborted = lpagery_root()->syncQueueRepository()->abort_app_run($process_id, $creation_id);

        return rest_ensure_response(["success" => true, "aborted" => $aborted]);
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

    /**
     * The author picker: users who can author the post type of `post_type` or of the Template
     * Page `template_id`, searched and paged.
     */
    public function get_users(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params() ?: [];
        [$page, $per_page] = $this->lookup_paging($json_params);
        try {
            $result = lpagery_root()->lookupService()->search_users(
                $this->lookup_context($json_params),
                sanitize_text_field($json_params['search'] ?? ''),
                $page,
                $per_page
            );
        } catch (\InvalidArgumentException $exception) {
            return new \WP_Error('lpagery_invalid_lookup', $exception->getMessage(), ['status' => 400]);
        }
        return rest_ensure_response($result);
    }

    /**
     * Checks one column's values against the site in one request. The values are left as sent,
     * because each answer is keyed by its value exactly as the App sent it.
     */
    public function resolve_values(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params() ?: [];
        $values = is_array($json_params['values'] ?? null) ? $json_params['values'] : [];
        $values = array_values(array_map('strval', array_filter($values, 'is_scalar')));
        try {
            $result = lpagery_root()->lookupService()->resolve_values(
                sanitize_text_field($json_params['kind'] ?? ''),
                $values,
                $this->lookup_context($json_params)
            );
        } catch (\InvalidArgumentException $exception) {
            return new \WP_Error('lpagery_invalid_lookup', $exception->getMessage(), ['status' => 400]);
        }
        return rest_ensure_response($result);
    }

    /**
     * The media picker: the media library's images, searched by title and file name and paged.
     */
    public function search_media(\WP_REST_Request $request)
    {
        $json_params = $request->get_json_params() ?: [];
        [$page, $per_page] = $this->lookup_paging($json_params);
        $result = lpagery_root()->lookupService()->search_media(sanitize_text_field($json_params['search'] ?? ''), $page, $per_page);
        return rest_ensure_response($result);
    }

    /**
     * @return array<string, mixed> what a lookup runs against
     */
    private function lookup_context(array $json_params): array
    {
        return [
            'post_type' => sanitize_text_field($json_params['post_type'] ?? ''),
            'template_id' => intval($json_params['template_id'] ?? 0),
            'taxonomy' => sanitize_text_field($json_params['taxonomy'] ?? ''),
            'language' => sanitize_text_field($json_params['language'] ?? ''),
        ];
    }

    /**
     * @return int[] the 1-based page and the page size, capped
     */
    private function lookup_paging(array $json_params): array
    {
        $page = max(1, intval($json_params['page'] ?? 1));
        $per_page = intval($json_params['per_page'] ?? LookupService::DEFAULT_PER_PAGE);
        return [$page, min(LookupService::MAX_PER_PAGE, max(1, $per_page))];
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

        // A code is single-use: burn it on any failure so a leaked code cannot be retried.
        $nonce = (string)($auth_data["nonce"] ?? '');
        $nonce_from_request = (string)$request->get_header('X-LPagery-WP-Nonce');
        if ($nonce === '' || $nonce_from_request === '' || !hash_equals($nonce, $nonce_from_request)) {
            delete_transient($transient_key);
            return new \WP_Error('invalid_nonce', 'Invalid nonce', ['status' => 403]);
        }
        // Claim the code: only the request whose delete actually removed it may mint a token, so two
        // concurrent exchanges of the same code cannot both succeed.
        if (!delete_transient($transient_key)) {
            return new \WP_Error('invalid_code', 'Invalid or expired code', ['status' => 400]);
        }

        $user_id = intval($auth_data["user_id"] ?? 0);
        $user = get_user_by('id', $user_id);
        if (!$user || !user_can($user, 'manage_options')) {
            return new \WP_Error('lpagery_app_forbidden', 'User cannot authorize this site', ['status' => 403]);
        }
        $app_user_mail_address = sanitize_email($auth_data["app_user_mail_address"] ?? '');


        // Generate a secure random token and a per-token transport secret
        $token = wp_generate_password(32, false);
        $secret = wp_generate_password(64, false);

        // Store the hashed token together with its per-token secret
        $token_id = $this->store_token($user_id, $token, $app_user_mail_address, $secret);

        // Echo the per-token secret once so the app can encrypt new-style bearers
        // with it; only its stored copy remains on the row afterwards (issue #187).
        return rest_ensure_response(['token' => $token, 'id' => $token_id, 'secret' => $secret, 'supports_per_token_secret' => true]);
    }
    private function store_token($user_id, $token, $app_user_mail_address, $secret)
    {
        // Use SHA-256 for API tokens instead of bcrypt - much faster for high-entropy tokens
        $hashed_token = hash('sha256', $token);

        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';
        $wpdb->insert($table_name, ['user_id' => $user_id,
            'token' => $hashed_token,
            'app_user_mail_address' => $app_user_mail_address,
            'secret' => $secret,]);

        return (int) $wpdb->insert_id;
    }

}