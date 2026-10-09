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
                "SELECT ID, user_login, user_email FROM $wpdb->users
                 WHERE ID IN ($in_ids) OR user_email IN ($in_values) OR user_login IN ($in_values)",
                array_merge($ids, $chunk, $chunk)
            )) ?: []);
        }

        // Email and login compare without case, like the database does.
        $names = [];
        $found_ids = [];
        foreach ($users as $user) {
            $found_ids[(int)$user->ID] = true;
            $names[strtolower((string)$user->user_email)] = true;
            $names[strtolower((string)$user->user_login)] = true;
        }
        return array_values(array_filter($values, function ($value) use ($found_ids, $names) {
            return (is_numeric($value) && isset($found_ids[(int)$value])) || isset($names[strtolower($value)]);
        }));
    }
}
