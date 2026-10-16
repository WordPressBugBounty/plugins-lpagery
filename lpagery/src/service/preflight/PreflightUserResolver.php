<?php

namespace LPagery\service\preflight;

/**
 * Finds which `lpagery_author` values name a user, the way the run looks each one up
 * ({@see \LPagery\service\DynamicPageAttributeHandler::lpagery_get_author()}): by id, then by email,
 * then by login. One query per 1,000 distinct values, however many rows share a value.
 */
class PreflightUserResolver
{
    private const CHUNK_SIZE = 1000;

    /**
     * @param string[] $values distinct values
     * @return string[] the values that name a user
     */
    public function find_users(array $values): array
    {
        return array_map('strval', array_keys($this->resolve_users($values)));
    }

    /**
     * The user each value names, keyed by the value. A value that names no user is left out.
     *
     * @param string[] $values distinct values
     * @return array<string, array{id: int, display_name: string}>
     */
    public function resolve_users(array $values): array
    {
        global $wpdb;
        $values = array_values(array_unique(array_map('strval', $values)));
        if (empty($values)) {
            return [];
        }
        $users = [];
        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            $ids = array_values(array_filter(array_map('intval', array_filter($chunk, 'is_numeric')), function ($id) {
                return $id > 0;
            }));
            $in_ids = empty($ids) ? '0' : implode(', ', array_fill(0, count($ids), '%d'));
            $in_values = implode(', ', array_fill(0, count($chunk), '%s'));
            $users = array_merge($users, $wpdb->get_results($wpdb->prepare(
                "SELECT ID, user_login, user_email, display_name FROM $wpdb->users
                 WHERE ID IN ($in_ids) OR user_email IN ($in_values) OR user_login IN ($in_values)",
                array_merge($ids, $chunk, $chunk)
            )) ?: []);
        }

        // Email and login compare without case, like the database does.
        $by_id = [];
        $by_email = [];
        $by_login = [];
        foreach ($users as $user) {
            $found = ['id' => (int)$user->ID, 'display_name' => (string)$user->display_name];
            $by_id[(int)$user->ID] = $found;
            $by_email[strtolower((string)$user->user_email)] = $found;
            $by_login[strtolower((string)$user->user_login)] = $found;
        }
        $resolved = [];
        foreach ($values as $value) {
            $key = strtolower($value);
            if (is_numeric($value) && isset($by_id[(int)$value])) {
                $resolved[$value] = $by_id[(int)$value];
            } elseif (isset($by_email[$key])) {
                $resolved[$value] = $by_email[$key];
            } elseif (isset($by_login[$key])) {
                $resolved[$value] = $by_login[$key];
            }
        }
        return $resolved;
    }
}
