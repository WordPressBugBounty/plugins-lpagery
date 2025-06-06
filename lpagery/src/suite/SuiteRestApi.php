<?php

namespace LPagery\suite;

use LPagery\controller\DuplicatedSlugController;
use LPagery\controller\PostController;
use LPagery\controller\ProcessController;
use LPagery\controller\SlugController;
use LPagery\controller\TaxonomyController;
use LPagery\data\LPageryDao;
use LPagery\factories\CreatePostControllerFactory;
use LPagery\io\Mapper;
use LPagery\service\delete\DeletePageService;
use LPagery\service\settings\SettingsController;
use LPagery\utils\Utils;

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
            'permission_callback' => '__return_true',]);
        register_rest_route('lpagery/app/v1', '/get_pages', ['methods' => 'POST',
            'callback' => [$this,
                'get_pages'],
            'permission_callback' => '__return_true',]);
        register_rest_route('lpagery/app/v1', '/upsert_process', ['methods' => 'POST',
            'callback' => [$this,
                'upsert_process'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/sanitize_slug', ['methods' => 'POST',
            'callback' => [$this,
                'sanitize_slug'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/get_post_title_as_slug', ['methods' => 'POST',
            'callback' => [$this,
                'get_post_title_as_slug'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/get_taxonomies', ['methods' => 'POST',
            'callback' => [$this,
                'get_taxonomies'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/get_taxonomy_terms', ['methods' => 'POST',
            'callback' => [$this,
                'get_taxonomy_terms'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/get_process', ['methods' => 'POST',
            'callback' => [$this,
                'get_process'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/create_page', ['methods' => 'POST',
            'callback' => [$this,
                'create_page'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/disconnect_page_set', ['methods' => 'POST',
            'callback' => [$this,
                'disconnect_page_set'],
            'permission_callback' => '__return_true',]);


        register_rest_route('lpagery/app/v1', '/connect_page_set', ['methods' => 'POST',
            'callback' => [$this,
                'connect_page_set'],
            'permission_callback' => '__return_true',]);


        register_rest_route('lpagery/app/v1', '/search_processes', ['methods' => 'POST',
            'callback' => [$this,
                'search_processes'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/check_duplicated_slugs', ['methods' => 'POST',
            'callback' => [$this,
                'check_duplicated_slugs'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/delete_pages', ['methods' => 'POST',
            'callback' => [$this,
                'delete_pages'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/get_pages_for_update', ['methods' => 'POST',
            'callback' => [$this,
                'get_pages_for_update'],
            'permission_callback' => '__return_true',]);

        register_rest_route('lpagery/app/v1', '/get_pages_for_delete', ['methods' => 'POST',
            'callback' => [$this,
                'get_pages_for_delete'],
            'permission_callback' => '__return_true',]);
    }

    private function store_token($user_id, $token, $app_user_mail_address)
    {
        $hashed_token = password_hash($token, PASSWORD_DEFAULT);

        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';
        $wpdb->insert($table_name, ['user_id' => $user_id,
            'token' => $hashed_token,
            'app_user_mail_address' => $app_user_mail_address,]);
    }


    public function get_post(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $post_id = intval($request->get_json_params() ['post_id']);
        $post_controller = PostController::get_instance();
        $post_data = $post_controller->getPost($post_id);
        return rest_ensure_response($post_data);
    }

    public function get_pages(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        $search = sanitize_text_field($json_params ['search'] ?? '');
        $mode = sanitize_text_field($json_params ['mode'] ?? '');
        $select = sanitize_text_field($json_params ['select'] ?? '');
        $template_id = intval($json_params ['template_id'] ?? 0);
        $post_controller = PostController::get_instance();
        $custom_post_types = SettingsController::get_instance()->getEnabledCustomPostTypes();
        $post_data = $post_controller->getPosts($search, $custom_post_types, $mode, $select, $template_id);
        return rest_ensure_response($post_data);
    }

    public function upsert_process(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        error_log(json_encode($json_params));
        $upsertParams = \LPagery\model\UpsertProcessParams::fromArray($json_params, "app");
        $result = ProcessController::get_instance()->upsertProcess($upsertParams);

        return rest_ensure_response($result);
    }

    public function sanitize_slug(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        $parent_id = (int)$json_params['parent_id'];
        $template_id = (int)($json_params["template_id"] ?? 0);
        $slug = sanitize_text_field($json_params['slug'] ?? '');

        $slugController = SlugController::get_instance();
        $result = $slugController->sanitizeSlug($slug, $parent_id, $template_id);


        return rest_ensure_response($result);
    }

    public function get_post_title_as_slug(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        $post_id = (int)$json_params['post_id'];


        $slugController = SlugController::get_instance();
        $result = $slugController->getPostTitleAsSlug($post_id);


        return rest_ensure_response($result);
    }

    public function get_taxonomies(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        $post_type = sanitize_text_field($json_params['post_type']);

        $result = TaxonomyController::get_instance()->getTaxonomies($post_type);

        return rest_ensure_response($result);
    }

    public function get_taxonomy_terms(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }

        $result = TaxonomyController::get_instance()->getTaxonomyTerms();

        return rest_ensure_response($result);
    }


    public function get_process(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();

        $id = intval($json_params['id']);
        $result = ProcessController::get_instance()->getProcessDetails($id);

        return rest_ensure_response($result);
    }

    public function disconnect_page_set(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();

        $id = intval($json_params['id']);
        ProcessController::get_instance()->updateManagingSystem($id, "plugin");

        return rest_ensure_response(["success" => true]);
    }

    public function connect_page_set(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();

        $id = intval($json_params['id']);
        $process = ProcessController::get_instance()->updateManagingSystem($id, "app");

        $data = maybe_unserialize($process->data);
        return rest_ensure_response(["success" => true,
            "name" => $process->purpose ?? '',
            "slug_pattern" => $data["slug"]]);
    }

    public function search_processes(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        $search = sanitize_text_field($json_params['search']);

        $processes = ProcessController::get_instance()->searchProcesses(null, $user_id, $search, "", "plugin");

        return rest_ensure_response($processes);
    }

    public function check_duplicated_slugs(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        try {
            $slug = isset($json_params['slug']) ? Utils::lpagery_sanitize_title_with_dashes($json_params['slug']) : null;
            $process_id = isset($json_params['process_id']) ? intval($json_params['process_id']) : -1;
            $data = $json_params['data'] ?? null;
            $template_id = intval($json_params['post_id']);
            $parent_id = intval($json_params['parent_id'] ?? 0);
            $includeParentAsIdentifier = rest_sanitize_boolean($json_params["includeParentAsIdentifier"] ?? false);
            $json_decode = $json_params['keys'];
            $keys = isset($json_params['keys']) ? array_map('sanitize_text_field', $json_decode) : [];

            $duplicatedSlugController = DuplicatedSlugController::get_instance();
            $result = $duplicatedSlugController->getDuplicatedSlugs($data, $template_id, $includeParentAsIdentifier,
                $parent_id, $slug, $process_id, $keys, false);

            return rest_ensure_response($result);
        } catch (\Throwable $throwable) {
            error_log($throwable->__toString());
            return rest_ensure_response(array("success" => false,
                "exception" => $throwable->__toString()));
        }
    }

    public function create_page(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }

        $json_params = $request->get_json_params();
        ob_start();

        try {
            $createPostController = CreatePostControllerFactory::create();
            $index = intval($json_params["index"]);
            if ($index == 0) {
                $process_id = (int)$json_params['process_id'];
                LPageryDao::get_instance()->lpagery_update_process_sync_status($process_id, "RUNNING");
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
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        $slugs = $json_params['slugs'];
        $process_id = intval($json_params['process_id']);
        $sanitized_slugs = array_map('sanitize_text_field', $slugs);

        $LPageryDao = LPageryDao::get_instance();
        $result = $LPageryDao->lpagery_get_process_posts_slugs($process_id);
        $post_ids = [];
        foreach ($result as $post_slug_entry) {
            if (!$post_slug_entry->client_generated_slug || in_array($post_slug_entry->client_generated_slug, $sanitized_slugs)) {
                continue;
            }
            $post_ids[] = $post_slug_entry->post_id;
        }
        if (!empty($post_ids)) {
            error_log('Deleting pages: ' . json_encode($post_ids));
            DeletePageService::getInstance($LPageryDao)->deletePages($post_ids);
        }

        return rest_ensure_response(["success" => true]);
    }

    public function get_pages_for_delete(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();
        $slugs = $json_params['slugs'];
        $process_id = intval($json_params['process_id']);
        $sanitized_slugs = array_map('sanitize_text_field', $slugs);

        $LPageryDao = LPageryDao::get_instance();
        $result = $LPageryDao->lpagery_get_process_posts_slugs($process_id);
        $posts = [];
        foreach ($result as $post_slug_entry) {
            if (!$post_slug_entry->client_generated_slug || in_array($post_slug_entry->client_generated_slug,
                    $sanitized_slugs)) {
                continue;
            }
            $post = get_post($post_slug_entry->post_id);
            $posts[] = array("post_title" => $post->post_title, "url" => get_permalink($post->ID), "id" => $post->ID,
                "slug" => $post->post_name, "post_type" => $post->post_type);
        }


        return rest_ensure_response($posts);
    }



    function get_pages_for_update(\WP_REST_Request $request)
    {
        $user_id = $this->verify_token($request);
        if (!$user_id) {
            return new \WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        $json_params = $request->get_json_params();

        $process_id = (intval($json_params["process_id"] ?? 0));
        $LPageryDao = LPageryDao::get_instance();

        $posts = $LPageryDao->lpagery_get_existing_posts_for_update_modal(null, $process_id);
        $mapper = Mapper::get_instance();
        $mapped = array_map([$mapper,
            'lpagery_map_post_for_update_modal'], $posts);
        return rest_ensure_response($mapped);
    }

    private function verify_token(\WP_REST_Request $request)
    {
        $provided_token = $request->get_header('Authorization');
        $user_id = intval($request->get_header('X-LPagery-User-ID'));


        if (!$provided_token || !$user_id) {
            return false;
        }
        $provided_token = str_replace('Bearer ', '', $provided_token);


        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';

        // Get the token for the specific user
        $prepare = $wpdb->prepare("SELECT token, user_id, id FROM $table_name WHERE user_id = %d", $user_id);
        $token_records = $wpdb->get_results($prepare);

        if (!$token_records) {
            return false;
        }
        foreach ($token_records as $token_record) {
            if (password_verify($provided_token, $token_record->token)) {
                $user = get_user_by("id", $token_record->user_id);
                if (!$user) {
                    return false;
                }
                $wpdb->update($table_name, ['last_used_at' => current_time('mysql')], ['id' => $token_record->id]);
                wp_set_current_user($token_record->user_id);
                return $token_record->user_id;
            }
        }


        return false;
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
}