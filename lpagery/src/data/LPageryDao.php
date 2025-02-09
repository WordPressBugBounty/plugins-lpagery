<?php

namespace LPagery\data;

use Exception;
use LPagery\factories\InputParamProviderFactory;
use LPagery\factories\SubstitutionHandlerFactory;
use LPagery\model\Params;
use LPagery\utils\Utils;
use LPagery\wpml\WpmlHelper;

class LPageryDao
{
    private static $instance;


    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init_db()
    {
        $LPageryDatabaseMigrator = new LPageryDatabaseMigrator();
        $LPageryDatabaseMigrator->migrate();
    }

    public function lpagery_has_template_pending_changes($id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        if (self::lpagery_table_exists()) {
            $prepare = $wpdb->prepare("select (count(p.ID) > 0) as post_exists
					from $wpdb->posts p
					         inner join $table_name_process_post lpp on p.ID = lpp.post_id
					        inner join $wpdb->posts  source_post on lpp.template_id = source_post.id
					where lpp.template_id = %s
					  and p.post_status != 'trash' and source_post.post_modified > lpp.modified
					order by p.id", $id);
            $result = (array)$wpdb->get_results($prepare)[0];

            return boolval($result['post_exists']);
        }
        return false;
    }

    public function lpagery_get_existing_posts_for_update_modal($template_id, $process_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        if (!$template_id) {
            throw new Exception("Template ID is required");
        }

        $query = "SELECT p.ID, 
                     p.post_parent as parent_id, 
                     p.post_status as post_status, 
                     lpp.lpagery_process_id AS process_id, 
                     lpp.template_id as template_id, 
                     p.post_title, 
                     p.post_name,  
                     p.post_type,
                    lpp.page_manually_updated_at,
                    lpp.page_manually_updated_by,    
                    lpp.replaced_slug AS replaced_slug,
                    lpp.parent_search_term AS parent_search_term
                    
                    
              FROM {$wpdb->posts} p
              INNER JOIN {$table_name_process_post} lpp ON p.ID = lpp.post_id
              WHERE p.post_status != 'trash'";

        if ($process_id) {
            $query .= " AND lpp.lpagery_process_id = %s GROUP BY p.ID ORDER BY p.ID";
            $prepare = $wpdb->prepare($query, $process_id, $process_id);
        } else {
            $query .= " AND lpp.template_id = %s GROUP BY p.ID ORDER BY p.ID";
            $prepare = $wpdb->prepare($query, $template_id);
        }

        $results = $wpdb->get_results($prepare);

        return $results;
    }

    public function lpagery_upsert_process($post_id, $process_id, $purpose, $data, $google_sheet_data, $google_sheet_sync_enabled, bool $include_parent_as_identifier)
    {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        if (($process_id) <= 0) {
            $wpdb->insert($table_name_process, array("post_id" => $post_id,
                "user_id" => $current_user_id,
                "purpose" => $purpose,
                "data" => serialize($data),
                "google_sheet_data" => serialize($google_sheet_data),
                "google_sheet_sync_enabled" => $google_sheet_sync_enabled,
                "google_sheet_sync_status" => $google_sheet_sync_enabled ? "PLANNED" : null,
                "include_parent_as_identifier" => $include_parent_as_identifier,
                "created" => current_time('mysql')));
            if ($wpdb->last_error) {
                throw new Exception("Failed to insert process " . $wpdb->last_error);
            }
            return $wpdb->insert_id;
        } else {
            if ($data) {
                $wpdb->query("START TRANSACTION");
                $existing_process = self::lpagery_get_process_by_id($process_id);
                $old_slug = maybe_unserialize($existing_process->data)["slug"];
                $new_slug = $data["slug"];
                $wpdb->update($table_name_process, array("data" => serialize($data),
                    "include_parent_as_identifier" => $include_parent_as_identifier), array("id" => $process_id));
                if ($old_slug !== $new_slug) {
                    $process_posts = self::lpagery_get_process_post_input_data($process_id);
                    try {
                        foreach ($process_posts as $process_post) {
                            $params = InputParamProviderFactory::create()->lpagery_get_input_params_without_images(maybe_unserialize($process_post->data));
                            $slug = SubstitutionHandlerFactory::create()->lpagery_substitute_slug($params, $new_slug);
                            $slug_with_braces = Utils::lpagery_sanitize_title_with_dashes($slug);
                            $slug = sanitize_title($slug);
                            if ($slug_with_braces !== $slug) {
                                throw new Exception("Slug $slug_with_braces is not valid. Please make sure to only use placeholders which are available in the current data. If you want to add new data to the slug, please make sure to update the content first.");
                            }
                            $wpdb->update($table_name_process_post, array("replaced_slug" => $slug),
                                array("id" => $process_post->id));
                        }
                    } catch (Exception $e) {
                        $wpdb->query("ROLLBACK");
                        throw $e;
                    }
                }
                $wpdb->query("COMMIT");
            }
            if ($google_sheet_data) {
                $wpdb->update($table_name_process, array("google_sheet_data" => serialize($google_sheet_data),
                    "google_sheet_sync_enabled" => $google_sheet_sync_enabled), array("id" => $process_id));
            }
            if ($purpose) {
                $wpdb->update($table_name_process, array("purpose" => $purpose), array("id" => $process_id));
            }
            if ($wpdb->last_error) {
                throw new Exception("Failed to upsert process " . $wpdb->last_error);
            }
        }

        return $process_id;
    }

    public function lpagery_save_process_sheet_data($process_id, $google_sheet_data, $google_sheet_sync_enabled)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $wpdb->update($table_name_process, array("google_sheet_data" => serialize($google_sheet_data),
            "google_sheet_sync_enabled" => $google_sheet_sync_enabled), array("id" => $process_id));


        return $process_id;
    }

    public function lpagery_add_post_to_process(Params $params, $post_id, $template_id, $replaced_slug, $shouldContentBeUpdated, $parent_id, $parent_search_term)
    {
        global $wpdb;

        $wpdb->suppress_errors = true;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $table_name_process = $wpdb->prefix . 'lpagery_process';

        $process_id = $params->process_id;
        $sanitized_slug = sanitize_title($replaced_slug);
        $prepare = $wpdb->prepare("select lpp.id from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = %s and lpp.replaced_slug = %s and lpp.post_id != %s and p.post_parent = %s",
            $process_id, $sanitized_slug, $post_id, $parent_id);
        $existing_process_post_with_another_id = $wpdb->get_results($prepare);
        if ($existing_process_post_with_another_id) {
            return array("created_id" => null,
                "error" => "Post with the same slug $sanitized_slug already exists in process $process_id ");
        }

        $process_data = $wpdb->get_results($wpdb->prepare("SELECT data FROM $table_name_process where id = %s",
            $process_id));

        $spintax_enabled = $params->spintax_enabled;
        $image_processing_enabled = $params->image_processing_enabled;
        $process_config = !empty($process_data) ? $process_data[0]->data : null;
        $lpagery_settings = serialize(array("spintax_enabled" => $spintax_enabled,
            "image_processing_enabled" => $image_processing_enabled));

        $prepare = $wpdb->prepare("select lpp.id from $table_name_process_post lpp where lpp.lpagery_process_id = %s and lpp.post_id = %s",
            $process_id, $post_id);
        $process_post_already_exists = $wpdb->get_results($prepare);
        if ($process_post_already_exists) {
            $update_array = array("data" => serialize($params->raw_data),
                "replaced_slug" => $sanitized_slug,
                "config" => $process_config,
                "lpagery_settings" => $lpagery_settings,
                "parent_search_term" => $parent_search_term,
                "template_id" => $template_id,
                "modified" => current_time('mysql'));
            if ($shouldContentBeUpdated) {
                $update_array["page_manually_updated_at"] = null;
                $update_array["page_manually_updated_by"] = null;
            }
            $update_result = $wpdb->update($table_name_process_post, $update_array, array("post_id" => $post_id,
                "lpagery_process_id" => $process_id));

            if ($update_result === false) {
                throw new Exception("Failed to update post $post_id in process $process_id " . $wpdb->last_error);
            }
        } else {
            $wpdb->insert($table_name_process_post, array("post_id" => $post_id,
                "lpagery_process_id" => $process_id,
                "data" => serialize($params->raw_data),
                "created" => current_time('mysql'),
                "replaced_slug" => $sanitized_slug,
                "config" => $process_config,
                "parent_search_term" => $parent_search_term,
                "lpagery_settings" => $lpagery_settings,
                "template_id" => $template_id,
                "modified" => current_time('mysql')));
            if (!$wpdb->insert_id || $wpdb->last_error) {
                throw new Exception("Failed to add post $post_id to process $process_id " . $wpdb->last_error);
            }
        }
        $wpdb->suppress_errors = false;

        return array("created_id" => $wpdb->insert_id,
            "error" => null);
    }


    public function lpagery_get_processes_by_source_post($post_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select id,
			       user_id,
			       post_id,
			       data,
			       created,
    				purpose,
			       (select count(lpp.id) from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id  where lpp.lpagery_process_id = lp.id and p.post_status != 'trash') as count
			from $table_name_process lp
			where post_id = %s", $post_id);

        return $wpdb->get_results($prepare);

    }

    public function lpagery_search_processes($post_id, $user_id, $search, $empty_filter)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $table_name_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $where_query = array();

        if (is_numeric($post_id) && $post_id > 0) {
            $where_query[] = "post_id=" . $post_id;
        }
        if (is_numeric($user_id) && $user_id > 0) {
            $where_query[] = "user_id=" . $user_id;
        }
        if (!empty($search) && $search != 'undefined') {
            $prepared = '%%%' . $search . '%%';
            $where_query[] = sprintf("purpose like '%s'", $prepared);
        }

        // Add empty filter condition
        if ($empty_filter === 'non-empty') {
            $where_query[] = "(select count(lpp.id) from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = lp.id and p.post_status != 'trash') > 0";
        } elseif ($empty_filter === 'empty') {
            $where_query[] = "(select count(lpp.id) from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = lp.id and p.post_status != 'trash') = 0";
        }

        if (empty($where_query)) {
            $where_query[] = "true";
        }
        $where_query_text = " WHERE " . implode(' AND ', $where_query) . " ORDER BY created desc";

        $prepare = "select id,
               user_id,
               google_sheet_sync_enabled,
               google_sheet_sync_status,
               last_google_sheet_sync,
               google_sheet_data,
               post_id,
               created,
                purpose,
                (select count(lq.id) from $table_name_queue lq where lq.process_id = lp.id and error is not null) as errored,
                (select count(lq.id) from $table_name_queue lq where lq.process_id = lp.id and  error is null) as in_queue,
                (select count(lpp.id) from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = lp.id and p.post_status != 'trash') as count
            from $table_name_process lp $where_query_text";

        return $wpdb->get_results($prepare);
    }

    public function lpagery_get_process_by_id($process_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select id,
       				purpose,
			       user_id,
			       post_id,
			       data,
			       google_sheet_sync_enabled,
			       google_sheet_sync_status,
			       google_sheet_sync_error,
			       last_google_sheet_sync,
			       google_sheet_data,
			       queue_count,
			       processed_queue_count,
			       include_parent_as_identifier,
			       created,
			         (select count(lpp.id) from $table_name_process_post lpp inner join $wpdb->posts p on p.id = lpp.post_id where lpp.lpagery_process_id = lp.id and p.post_status != 'trash') as count
			from $table_name_process lp
			where id = %s", $process_id);

        $results = $wpdb->get_results($prepare);
        return empty($results) ? null : $results[0];

    }

    public function lpagery_get_process_by_created_post_id($created_post_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lp.id,
       				 lp.purpose,
			        lp.user_id,
			       lp.post_id as post_id,
			       lp.data,
			       lp.google_sheet_sync_enabled,
			        lp.google_sheet_sync_status,
			        lp.google_sheet_sync_error,
			        lp.last_google_sheet_sync,
			        lp.google_sheet_data,
			        lp.queue_count,
			        lp.processed_queue_count,
			        lp.created,
			          lp.include_parent_as_identifier,
			         (select count(lpp_count.id) from $table_name_process_post lpp_count inner join $wpdb->posts p on p.id = lpp_count.post_id where lpp_count.lpagery_process_id = lp.id and p.post_status != 'trash') as count

			from $table_name_process lp
			inner join $table_name_process_post lpp on lpp.lpagery_process_id = lp.id
			where lpp.post_id = %s", $created_post_id);

        $results = $wpdb->get_results($prepare);
        return empty($results) ? null : $results[0];

    }


    public function lpagery_update_process_post_data($process_id, $data, $post_id, $slug, $replaced_slug)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->update($table_name_process_post, array("data" => serialize($data),
            "replaced_slug" => $replaced_slug,
            "modified" => current_time('mysql')), array("post_id" => $post_id,
            "lpagery_process_id" => $process_id));

        return $wpdb->insert_id;
    }

    public function lpagery_update_process_modified($post_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->update($table_name_process_post, array("modified" => current_time('mysql')),
            array("post_id" => $post_id));

        return $wpdb->insert_id;
    }

    public function lpagery_get_process_post_input_data($process_id, $download = false)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.id, post_id, data
			from $table_name_process_post lpp
			inner join $wpdb->posts p on p.id = lpp.post_id
			where lpagery_process_id = %s and p.post_status != 'trash' order by lpp.post_id", $process_id);
        $results = $wpdb->get_results($prepare);
        if ($download) {
            $results = array_map(function ($value) {
                $permalink = get_permalink($value->post_id);
                $value->permalink = $permalink;
                return $value;
            }, $results);

        }
        return $results;
    }

    public function lpagery_get_process_post_data($generated_post_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select lpp.data as data, lpp.template_id as source_id, lpp.lpagery_process_id as process_id, lpp.modified as modified,  lpp.page_manually_updated_at,  lpp.page_manually_updated_by
			from $table_name_process_post lpp
			inner join $wpdb->posts p on p.id = lpp.post_id
			where lpp.post_id = %s and p.post_status != 'trash' order by lpp.id", $generated_post_id);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return array();
        }
        return $results [0];
    }

    public function lpagery_get_users_with_processes()
    {
        global $wpdb;

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if (self::lpagery_table_exists()) {
            return $wpdb->get_results("select u.id, u.display_name from $wpdb->users u where exists(select id from $table_name_process lp where lp.user_id  = u.id)");
        }
        return array();

    }

    public function lpagery_get_template_posts()
    {
        global $wpdb;

        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if (self::lpagery_table_exists()) {
            return $wpdb->get_results("SELECT p.id, p.post_title as title FROM $wpdb->posts p where post_status in ('publish', 'draft','private', 'trash') and exists(select pr.id from $table_name_process pr where pr.post_id = p.id )");
        }
        return array();
    }

    public function lpagery_get_posts_by_process($process_id)
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("select p.id, p.post_type, p.post_title, p.post_name
				from $wpdb->posts p
         inner join $table_name_process_post lpp on p.ID = lpp.post_id where lpp.lpagery_process_id = %s and p.post_status != 'trash'",
            $process_id);

        return $wpdb->get_results($prepare);
    }

    public function lpagery_delete_process($process_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $table_name_queue = $wpdb->prefix . 'lpagery_sync_queue';
        $wpdb->delete($table_name_process_post, array("lpagery_process_id" => $process_id));
        $wpdb->delete($table_name_queue, array("process_id" => $process_id));
        $wpdb->delete($table_name_process, array("id" => $process_id));
    }

    public function lpagery_delete_process_post($post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->delete($table_name_process_post, array("post_id" => $post_id));
    }

    public function lpagery_find_post_by_name_and_type_equal($search_term, $post_type)
    {
        global $wpdb;
        $search_term = esc_sql($search_term);
        $search_term = strtolower($search_term);
        $prepare = $wpdb->prepare("select p.id as id, p.post_title as post_title
                from $wpdb->posts p where lower(post_name) = %s and post_type = %s and post_status in ('publish', 'draft', 'private')
            order by post_date
            limit 1;", $search_term, $post_type);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return (array)$results[0];
    }

    public function lpagery_find_post_by_id($id)
    {
        $post_type = get_post_type($id);
        global $wpdb;
        $id = intval($id);

        $prepare = $wpdb->prepare("select p.id as id, p.post_title as post_title
              from $wpdb->posts p
              where p.id = %s
                and p.post_type = %s
                and p.post_status in ('private', 'draft', 'publish')", $id, $post_type);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }

        return (array)$results[0];
    }


    public function lpagery_is_post_template_with_created_posts($post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select exists(select lpp.id
              from $table_name_process_post lpp
                       inner join $wpdb->posts p on p.ID = lpp.post_id
              where p.post_status != 'trash'
                and lpp.template_id = %s) as created_page_exists", $post_id);
        return filter_var($wpdb->get_results($prepare)[0]->created_page_exists, FILTER_VALIDATE_BOOLEAN);
    }

    public function lpagery_get_process_id_by_template($post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("select lpagery_process_id as process_id
              from $table_name_process_post
              where template_id = %s limit 1", $post_id);
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return (array)$results[0];
    }

    public function lpagery_get_existing_post_by_slug_in_process(int $process_id, string $slug, ?int $parent_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $parent_condition = $parent_id !== null ? "AND p.post_parent = %d" : "";
        $query_params = array($process_id,
            $slug);
        if ($parent_id !== null) {
            $query_params[] = $parent_id;
        }

        $prepare = $wpdb->prepare("select p.ID, post_title, lpagery_process_id as 'process_id', post_name,post_content_filtered,post_excerpt,post_content,post_status,post_parent,post_date, lpp.data as data, lpp.replaced_slug as replaced_slug, lpp.page_manually_updated_at, lpp.page_manually_updated_by
                    from $wpdb->posts p
                             inner join $table_name_process_post lpp on lpp.post_id = p.id
                    where lpp.lpagery_process_id = %s
                      $parent_condition
                    and (lpp.replaced_slug = %s) order by post_name", ...$query_params);
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            $prepare = $wpdb->prepare("select p.ID, post_title, lpagery_process_id as 'process_id', post_name,post_content_filtered,post_excerpt,post_content,post_status,post_parent,post_date, lpp.data as data, lpp.replaced_slug as replaced_slug, lpp.page_manually_updated_at, lpp.page_manually_updated_by
                    from $wpdb->posts p
                             inner join $table_name_process_post lpp on lpp.post_id = p.id
                    where lpp.lpagery_process_id = %s
                      $parent_condition
                    and (p.post_name = %s) order by post_name", ...$query_params);
            $results = $wpdb->get_results($prepare);
        }
        if (empty($results)) {
            return null;
        } else {
            return (array)$results[0];
        }
    }

    public function lpagery_get_existing_post_not_managed_by_lpagery(string $slug, string $post_type, ?int $parent)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("SELECT p.ID, p.post_name, p.post_type, p.post_parent
            FROM $wpdb->posts p
            WHERE p.post_type = %s
            AND p.post_status NOT IN ('inherit', 'attachment')
            AND p.post_name = %s
            AND p.post_parent = %d
            AND NOT EXISTS (
                SELECT 1 
                FROM $table_name_process_post lpp 
                WHERE lpp.post_id = p.ID
            )", $post_type, $slug, $parent ?? 0);

        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        return (array)$results[0];
    }

    public function lpagery_get_existing_post_by_id_in_process(int $id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        $prepare = $wpdb->prepare("select p.ID, post_title, lpagery_process_id as 'process_id', post_name,post_content_filtered,post_excerpt,post_content,post_status,post_parent,post_date, lpp.replaced_slug as replaced_slug,  lpp.page_manually_updated_at,  lpp.page_manually_updated_by
                    from $wpdb->posts p
                             inner join $table_name_process_post lpp on lpp.post_id = p.id
                    where (p.id = %s) order by post_name", $id);
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        } else {
            return (array)$results[0];
        }
    }

    public function lpagery_get_processes_with_google_sheet_sync()
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $results = $wpdb->get_results("select id, data, post_id, created, google_sheet_data
            from $table_name_process where google_sheet_sync_enabled");
        return array_map(function ($element) {
            return array("id" => $element->id,
                "created" => $element->created,
                "data" => maybe_unserialize($element->data),
                "google_sheet_data" => maybe_unserialize($element->google_sheet_data),
                "post_id" => $element->post_id);
        }, $results);
    }

    public function lpagery_get_process_for_google_sheet_sync($process_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $results = $wpdb->get_results($wpdb->prepare("select id, data, post_id, created, google_sheet_data
            from $table_name_process where google_sheet_sync_enabled and id = %s", $process_id));
        if (empty($results)) {
            return null;
        }
        $result = (array)$results[0];
        return array("id" => $result['id'],
            "created" => $result['created'],
            "data" => maybe_unserialize($result['data']),
            "google_sheet_data" => maybe_unserialize($result['google_sheet_data']),
            "post_id" => $result['post_id']);
    }

    public function lpagery_get_process_posts_slugs($process_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepared = $wpdb->prepare("select id, post_id, replaced_slug
            from $table_name_process_post where lpagery_process_id = %s", $process_id);
        $result = $wpdb->get_results($prepared);
        return $result;
    }

    public function lpagery_update_process_sync_status($process_id, $status, $error = null)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        if ($status == "ERROR") {
            $wpdb->update($table_name_process, array("google_sheet_sync_status" => $status,
                "google_sheet_sync_error" => $error), array("id" => $process_id,
                "google_sheet_sync_enabled" => true));
        } elseif ($status == "FINISHED") {
            $wpdb->update($table_name_process, array("google_sheet_sync_status" => $status,
                "google_sheet_sync_error" => null,
                "last_google_sheet_sync" => current_time('mysql', true)), array("id" => $process_id,
                "google_sheet_sync_enabled" => true));
        } else {
            $wpdb->update($table_name_process, array("google_sheet_sync_status" => $status), array("id" => $process_id,
                "google_sheet_sync_enabled" => true));
        }
    }

    public function lpagery_get_process_config_changed($process_id, $post_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("SELECT data FROM $table_name_process WHERE id = %s", $process_id);
        $process_data = $wpdb->get_var($prepare);
        $prepare = $wpdb->prepare("SELECT config FROM $table_name_process_post WHERE post_id = %s and lpagery_process_id = %s",
            $post_id, $process_id);
        $process_post_data = $wpdb->get_var($prepare);

        return ($process_data) !== ($process_post_data);

    }

    public function lpagery_get_process_post_global_settings($process_id, $post_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("SELECT lpagery_settings FROM $table_name_process_post WHERE post_id = %s and lpagery_process_id = %s",
            $post_id, $process_id);
        $lpagery_settings = $wpdb->get_var($prepare);
        return maybe_unserialize($lpagery_settings);

    }

    public function lpagery_get_existing_posts_by_slug($slug_with_parents, $process_id, $post_type, $template_id)
    {
        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        if (empty($slug_with_parents)) {
            //return array();
        }

        // Initialize language filtering variables
        $template_language = '';
        $language_join = '';
        $language_condition = '';

        // Check if WPML is installed and get template language if exists
        if (WpmlHelper::is_wpml_installed()) {
            $template_language = $wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations 
                    WHERE element_id = %d 
                    AND element_type = %s", $template_id, 'post_' . $post_type));

            // Only add WPML conditions if template has a language assigned
            if ($template_language) {
                $language_join = "LEFT JOIN {$wpdb->prefix}icl_translations icl 
                                ON icl.element_id = p.ID 
                                AND icl.element_type = %s";
                $language_condition = "AND icl.language_code = %s";
            }
        }


        // Base query without WPML filtering
        $query = "
            SELECT p.ID AS id, p.post_name, p.post_type, p.post_parent, exists(select id from $table_name_process_post lpp where lpp.post_id = p.id and lpp.lpagery_process_id != %d) as exists_in_other_set
            FROM $wpdb->posts p
            LEFT JOIN $table_name_process_post lpp
                ON lpp.post_id = p.ID 
                AND lpp.lpagery_process_id = %d
            $language_join
            WHERE p.post_type = %s
                AND p.post_status NOT IN ('inherit', 'attachment')
                AND lpp.post_id IS NULL
                $language_condition
        ";


        // Prepare query based on whether we have WPML language
        $prepared_query = $template_language ? $wpdb->prepare($query, $process_id,$process_id, 'post_' . $post_type, $post_type,
            $template_language) : $wpdb->prepare($query, $process_id,$process_id, $post_type);

        $all_posts = $wpdb->get_results($prepared_query);

        // Filter posts by the provided slugs
        $filtered_posts = array();

        foreach ($all_posts as $post) {
            $found_posts = array_filter($slug_with_parents, function ($element) use ($post) {
                return $element->slug == $post->post_name && $element->parent_id == $post->post_parent;

            });
            if (!empty($found_posts)) {
                $post->permalink = get_permalink($post->id);
                $post->exists_in_other_set = filter_var($post->exists_in_other_set, FILTER_VALIDATE_BOOLEAN);
                $filtered_posts[] = $post;
            }
        }

        return $filtered_posts;
    }

    public function lpagery_get_existing_attachments_by_slug($slugs)
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("SELECT p.ID, p.post_name
            FROM $wpdb->posts p
            WHERE p.post_type = 'attachment'"));

        $slugs_lookup = array_flip($slugs);
        $filtered_posts = array();
        foreach ($results as $post) {
            if (isset($slugs_lookup[$post->post_name])) {
                $post->permalink = admin_url("upload.php?item={$post->ID}");
                $filtered_posts[] = $post;
            }
        }

        return $filtered_posts;
    }


    private function lpagery_table_exists()
    {
        global $wpdb;

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT
                    TABLE_NAME
                FROM
                    information_schema.TABLES
                WHERE
                        TABLE_NAME = %s
            ) as lpagery_table_exists;", $table_name_process_post);
        $result = (array)$wpdb->get_results($prepare)[0];
        return $result['lpagery_table_exists'];
    }

    public function lpagery_count_processes()
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $prepare = $wpdb->prepare("SELECT count(*) as count FROM $table_name_process");
        $result = (array)$wpdb->get_results($prepare)[0];
        return $result['count'];
    }

    public function lpagery_get_first_process_date()
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $prepare = $wpdb->prepare("SELECT created FROM $table_name_process order by created asc limit 1");
        $results = $wpdb->get_results($prepare);
        if (empty($results)) {
            return null;
        }
        $result = (array)$results[0];
        return $result['created'];
    }

    public function lpagery_get_post_by_slug_for_link($slug)
    {
        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare("select p.id , post_title, post_type
                from $wpdb->posts p
                where post_name = %s
                  and post_status in ('private', 'publish', 'draft', 'future')", $slug));
        if (empty($results)) {
            $results = $wpdb->get_results($wpdb->prepare("select p.id , post_title, post_type
                from $wpdb->posts p
                where post_name like %s
                  and post_status in ('private', 'publish', 'draft', 'future')", $slug . '%'));
        }

        if (empty($results)) {
            return null;
        } else {
            return (array)$results[0];
        }
    }


    public function lpagery_get_post_at_position_in_process($post_id, $position, $circle)
    {

        global $wpdb;
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $prepare = $wpdb->prepare("select p.id, p.post_title
         from $wpdb->posts p
         inner join $table_name_process_post lpp on lpp.post_id = p.id
         where lpp.lpagery_process_id = (
             select lpagery_process_id
             from $table_name_process_post lpp2
             where lpp2.post_id = %s
         ) order by p.id", $post_id);
        $results = $wpdb->get_results($prepare);
        $results = (array)$results;
        if (empty($results)) {
            return null;
        }
        if ($position === 'FIRST') {
            return $results[0];
        }
        if ($position === 'LAST') {
            return $results[count($results) - 1];
        }
        if ($position === 'NEXT') {
            $position = 1;
        }
        if ($position === 'PREV') {
            $position = -1;
        }

        $index = -1;
        foreach ($results as $key => $result) {
            if ($result->id == $post_id) {
                $index = $key;
                break;
            }
        }

        if ($index != -1) {
            $target_index = $index + $position;
            if ($circle && $target_index > count($results) - 1) {
                return (array)$results[0];
            }
            if ($circle && $target_index < 0) {
                return (array)$results[count($results) - 1];
            }
            if ($target_index >= 0 && $target_index < count($results)) {
                return (array)$results[$target_index];
            }

        }

        return null;
    }

    public function lpagery_search_queue_items($process_id, $type, $slug)
    {
        global $wpdb;
        $table_name_queue = $wpdb->prefix . 'lpagery_sync_queue';

        $where_conditions = ["process_id = %d"];
        $query_params = [$process_id];

        // Add type filter
        if ($type === 'error') {
            $where_conditions[] = "error IS NOT NULL";
        } elseif ($type === 'queue') {
            $where_conditions[] = "error IS NULL";
        }

        // Add slug filter if provided
        if (!empty($slug)) {
            $where_conditions[] = "slug LIKE %s";
            $query_params[] = '%' . $wpdb->esc_like($slug) . '%';
        }

        $where_clause = implode(' AND ', $where_conditions);
        $prepare = $wpdb->prepare("SELECT id, slug, retry as retry_count, error 
            FROM $table_name_queue 
            WHERE $where_clause 
            LIMIT 1000", ...$query_params);

        return $wpdb->get_results($prepare);
    }

    public function is_sheet_data_downloading(): bool
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT id
                FROM $table_name_process
                WHERE google_sheet_sync_enabled
                AND google_sheet_sync_status IN ('DOWNLOADING_DATA', 'CRON_STARTED')
            ) as is_syncing;");
        $result = (array)$wpdb->get_results($prepare)[0];
        return boolval($result['is_syncing']);
    }

    public function get_next_set_to_be_synced(): ?int
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $next_id = $wpdb->get_var("SELECT id from $table_name_process where google_sheet_sync_status = 'CRON_STARTED' and google_sheet_sync_enabled LIMIT 1");

        if ($next_id) {
            return intval($next_id);
        }
        return null;

    }

    public function lpagery_update_process_template(int $processId, int $templateId)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $previous_template = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $table_name_process WHERE id = %d",
            $processId));
        $wpdb->update($table_name_process, array("post_id" => $templateId), array("id" => $processId));
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $wpdb->update($table_name_process_post, array("template_id" => $templateId),
            array("lpagery_process_id" => $processId,
                "template_id" => $previous_template));
        if ($wpdb->last_error) {
            throw new Exception("Failed to lpagery_update_process_template  " . $wpdb->last_error);
        }
    }

    public function lpagery_update_process_user(int $process_id, int $user_id)
    {
        global $wpdb;
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $wpdb->update($table_name_process, array("user_id" => $user_id), array("id" => $process_id));
        if ($wpdb->last_error) {
            throw new Exception("Failed to lpagery_update_process_user  " . $wpdb->last_error);
        }
    }
}

