<?php

namespace LPagery\suite;

use WP_REST_Request;
use WP_Error;

class TokenValidator
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }


    /**
     * Verify token from request and return user ID if valid
     */
    public function verify_token(WP_REST_Request $request)
    {

        $provided_token = $request->get_header('Authorization');
        $user_id = intval($request->get_header('X-LPagery-User-ID'));


        if (!$provided_token || !$user_id) {
            return new WP_Error('missing_credentials', 'Authorization header or User ID missing', array('status' => 401));
        }
        $bearer_token = str_replace('Bearer ', '', $provided_token);

        $secret_key = lpagery_fs()->get_site()->secret_key;

        list($iv_b64, $encrypted_b64) = explode(':', $bearer_token, 2);

        $iv = base64_decode($iv_b64);
        $encrypted_data = base64_decode($encrypted_b64);

        // Derive AES key
        $key = hash('sha256', $secret_key, true);
        // Decrypt
        $decrypted = openssl_decrypt(
            $encrypted_data,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            $ssl_error = openssl_error_string();
            $error_message = 'Failed to decrypt token';
            if ($ssl_error) {
                $error_message .= ': ' . $ssl_error;
            }
            error_log($error_message);
            return new WP_Error('decryption_failed', "decryption_failed", array('status' => 401));
        }

        $parts = explode('|', $decrypted);
        if (count($parts) !== 2) {
            return new WP_Error('invalid_token_format', 'Invalid token format', array('status' => 401));
        }

        list($token, $nonce) = $parts;

        // Validate nonce freshness (max 1 hour)
        $nonce_time = strtotime($nonce . ':00:00Z');
        $now = time();
        if (abs($now - $nonce_time) > 3600) {
            return new WP_Error('token_expired', 'Token has expired', array('status' => 401));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';

        // Hash the provided token for comparison (much faster than password_verify)
        $hashed_token = hash('sha256', $token);

        // Direct lookup by token hash and user ID - much more efficient
        $prepare = $wpdb->prepare("SELECT user_id, id FROM $table_name WHERE token = %s AND user_id = %d", $hashed_token, $user_id);
        $token_record = $wpdb->get_row($prepare);

        if (!$token_record) {
            return new WP_Error('invalid_token', 'Token verification failed', array('status' => 401));
        }

        $user = get_user_by("id", $token_record->user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 401));
        }
        
        // Update last used timestamp
        $wpdb->update($table_name, ['last_used_at' => current_time('mysql')], ['id' => $token_record->id]);
        wp_set_current_user($token_record->user_id);
        return $token_record->user_id;
    }



    /**
     * Permission callback that verifies the token
     */
    public function check_token_permission(WP_REST_Request $request)
    {
        $result = $this->verify_token($request);
        if (is_wp_error($result)) {
            return $result;
        }
        if(!$result) {
            return new WP_Error('invalid_token', 'Token verification failed', array('status' => 401));
        }
        return true;
    }
} 