<?php

namespace LPagery\suite;

use WP_REST_Request;
use WP_Error;
use LPagery\suite\BearerTokenDecryptor;

class TokenValidator
{
    public function __construct()
    {
    }


    /**
     * Verify token from request and return user ID if valid.
     *
     * Two paths share one crypto implementation (BearerTokenDecryptor):
     *  - New-style (X-LPagery-Token-ID present): locate the row by (id, user_id)
     *    and decrypt with that row's per-token secret, then constant-time compare
     *    the decrypted hash against the stored token hash.
     *  - Legacy (no locator header): decrypt with the Freemius site secret and
     *    look the row up by the decrypted token hash. Refused when the site has no
     *    Freemius secret, since hashing an empty one gives a publicly known key.
     *
     * Either way the token only authorizes while its user can still administer the
     * site (CVE-2026-110019).
     */
    public function verify_token(WP_REST_Request $request)
    {
        $provided_token = $request->get_header('Authorization');
        $user_id = intval($request->get_header('X-LPagery-User-ID'));
        $token_id = intval($request->get_header('X-LPagery-Token-ID'));

        if (!$provided_token || !$user_id) {
            return new WP_Error('missing_credentials', 'Authorization header or User ID missing', array('status' => 401));
        }

        // Case-insensitive strip so an HTTP/2-lowercased `bearer ` prefix still works.
        $bearer_token = preg_replace('/^Bearer\s+/i', '', $provided_token);

        $decryptor = new BearerTokenDecryptor();

        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';

        if ($token_id) {
            // New-style: locate the row up front and decrypt with its per-token secret.
            $prepare = $wpdb->prepare("SELECT user_id, id, token, secret FROM $table_name WHERE id = %d AND user_id = %d", $token_id, $user_id);
            $token_record = $wpdb->get_row($prepare);

            if (!$token_record || !$token_record->secret) {
                return new WP_Error('invalid_token', 'Token verification failed', array('status' => 401));
            }

            $decrypted = $decryptor->decrypt_and_validate($bearer_token, $token_record->secret, time());
            if (is_wp_error($decrypted)) {
                return $decrypted;
            }

            // Confirm the decrypted token matches the stored hash (constant time).
            if (!hash_equals($token_record->token, $decrypted['token_hash'])) {
                return new WP_Error('invalid_token', 'Token verification failed', array('status' => 401));
            }
        } else {
            // Legacy: decrypt with the Freemius site secret and look up by token hash.
            // Without a Freemius site there is no secret, and hashing an empty one yields a
            // publicly known key that anyone could encrypt a bearer with. Refuse instead.
            $site = lpagery_fs()->get_site();
            $secret_key = is_object($site) ? (string)($site->secret_key ?? '') : '';
            if ($secret_key === '') {
                return new WP_Error('missing_site_secret', 'Site secret unavailable', array('status' => 401));
            }

            $decrypted = $decryptor->decrypt_and_validate($bearer_token, $secret_key, time());
            if (is_wp_error($decrypted)) {
                return $decrypted;
            }

            // Direct lookup by token hash and user ID - much more efficient
            $prepare = $wpdb->prepare("SELECT user_id, id FROM $table_name WHERE token = %s AND user_id = %d", $decrypted['token_hash'], $user_id);
            $token_record = $wpdb->get_row($prepare);

            if (!$token_record) {
                return new WP_Error('invalid_token', 'Token verification failed', array('status' => 401));
            }
        }

        $user = get_user_by("id", $token_record->user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 401));
        }
        // An App Connection only keeps working while its user can still administer the site.
        // This also disarms tokens minted before the authorize flow was capability-gated.
        if (!user_can($user, 'manage_options')) {
            return new WP_Error('lpagery_app_forbidden', 'User cannot administer this site', array('status' => 403));
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