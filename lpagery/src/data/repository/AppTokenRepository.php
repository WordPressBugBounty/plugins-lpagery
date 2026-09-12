<?php

namespace LPagery\data\repository;

/**
 * Data access for the App tokens table (`lpagery_app_tokens`) — the tokens the companion mobile/app
 * integration authenticates with. Owns every read/write of that table: the list (admin-vs-owner
 * scoped), the delete-by-id, and the last-used stamp.
 *
 * The one focused seam for the App-token SQL, following the #195 {@see ViewRepository} tracer. These
 * method names never carried the `lpagery_` prefix.
 */
class AppTokenRepository
{
    /**
     * Retrieves all app tokens from the database
     *
     * @return array Array of app tokens
     */
    public function getAllAppTokens(): array
    {
        $get_all_tokens = current_user_can('manage_options');

        $user_id = get_current_user_id();
        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';

        if ($get_all_tokens) {
            $query = "SELECT id, user_id, created_at, last_used_at, app_user_mail_address
                      FROM $table_name
                      ORDER BY last_used_at DESC";
            $results = $wpdb->get_results($query);
        } else {
            $query = "SELECT id, user_id, created_at, last_used_at, app_user_mail_address
                      FROM $table_name
                      WHERE user_id = %d
                      ORDER BY last_used_at DESC";
            $results = $wpdb->get_results($wpdb->prepare($query, $user_id));
        }

        if (!$results) {
            return [];
        }

        return $results;
    }

    /**
     * Deletes an app token by ID
     *
     * @param int $tokenId The ID of the token to delete
     * @return bool Whether the operation was successful
     */
    public function deleteAppToken(int $tokenId): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';

        $result = $wpdb->delete(
            $table_name,
            ['id' => $tokenId],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Updates the last_used_at timestamp for a token
     *
     * @param string $token The token that was used
     * @return bool Whether the update was successful
     */
    public function updateTokenLastUsed(string $token): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lpagery_app_tokens';

        $result = $wpdb->update(
            $table_name,
            ['last_used_at' => current_time('mysql', true)],
            ['token' => $token],
            ['%s'],
            ['%s']
        );

        return $result !== false;
    }
}
