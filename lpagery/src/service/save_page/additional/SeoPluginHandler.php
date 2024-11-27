<?php

namespace LPagery\service\save_page\additional;

use LPagery\service\substitution\SubstitutionHandler;
use LPagery\model\Params;

class SeoPluginHandler
{
    private static $instance;
    private $substitutionHandler;

    private function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    public static function get_instance(SubstitutionHandler $substitutionHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($substitutionHandler);
        }
        return self::$instance;
    }

    public function lpagery_handle_seo_plugin($sourcePostId, $targetPostId, Params $params)
    {
        $post_meta_keys = get_post_custom_keys($sourcePostId);


        if (in_array('_aioseo_title', $post_meta_keys)) {
            self::lpagery_handle_aioseo($sourcePostId, $targetPostId, $params);
        }
    }

    private function lpagery_handle_aioseo($sourcePostId, $targetPostId, Params $params)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'aioseo_posts';
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT
                    TABLE_NAME
                FROM
                    information_schema.TABLES
                WHERE
                        TABLE_NAME = %s
            ) as aioseo_table_exists;", $table_name);
        $result = (array)$wpdb->get_results($prepare)[0];
        if (!$result['aioseo_table_exists']) {
            error_log("AIOSEO TABLE DOESNT EXIST");
            return false;
        }


        $original_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE post_id = %d order by created desc LIMIT 1",
            $sourcePostId
        ));

        if ($original_record) {
            $wpdb->delete($table_name, array("post_id" => $targetPostId));
            $new_record = $original_record;

            // Apply search and replace to each column
            foreach ($new_record as &$value) {
                if (is_string($value)) {
                    $value = $this->substitutionHandler->lpagery_substitute($params, $value);
                }
            }
            unset($new_record->id);
            $now = current_time('mysql', true);
            $new_record->created = $now;
            $new_record->updated = $now;
            $new_record->post_id = $targetPostId;

            // Insert the modified record as a new entry
            $wpdb->insert($table_name, (array)$new_record);

            return true; // Success
        }
        return false; // Failure
    }
}
