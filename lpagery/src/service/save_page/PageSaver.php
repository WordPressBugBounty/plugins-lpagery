<?php

namespace LPagery\service\save_page;

use Exception;
use LPagery\service\save_page\additional\AdditionalDataSaver;
use LPagery\data\LPageryDao;
use LPagery\model\Params;
use WP_Post;

class PageSaver
{
    private static ?PageSaver $instance = null;
    private LPageryDao $lpageryDao;
    private AdditionalDataSaver $additionalDataSaver;


    public function __construct(LPageryDao $lpageryDao, AdditionalDataSaver $additionalDataSaver)
    {
        $this->lpageryDao = $lpageryDao;
        $this->additionalDataSaver = $additionalDataSaver;
    }

    public static function get_instance(LPageryDao $lpageryDao, AdditionalDataSaver $additionalDataSaver)
    {
        if (null === self::$instance) {
            self::$instance = new self($lpageryDao, $additionalDataSaver);
        }
        return self::$instance;
    }


    /**
     * @throws Exception
     */
    public function savePage(WP_Post $template_post, Params $params, PostFieldProvider $postFieldProvider, array $processed_slugs, ?WP_Post $post_id_to_be_updated): SavePageResult
    {
        $slug = $postFieldProvider->get_slug();
        $json_decode = $params->raw_data;
        $process_id = $params->process_id;

        $slug_already_processed = $processed_slugs && count($processed_slugs) > 0 && (in_array($slug,
                $processed_slugs));
        $ignore_is_set = isset($json_decode["lpagery_ignore"]) && filter_var($json_decode["lpagery_ignore"],
                FILTER_VALIDATE_BOOLEAN);
        if (($slug_already_processed) || ($ignore_is_set)) {
            return new SavePageResult("ignored", $slug);
        }

        $transient_key = "lpagery_$process_id" . "_" . $slug;
        $process_slug_transient = get_transient($transient_key);
        if ($process_slug_transient) {
            error_log("LPagery Ignoring Post is already processing $slug");
            return new SavePageResult("ignored", $slug);
        }

        set_transient($transient_key, true, 10);
        $content = $postFieldProvider->get_content();
        $publish_datetime = $postFieldProvider->get_publish_datetime();
        $create_mode = !$post_id_to_be_updated;

        $new_post = ["ID" => $post_id_to_be_updated ? $post_id_to_be_updated->ID : null,
            'post_content' => $content,
            'post_content_filtered' => $postFieldProvider->get_content_filtered(),
            'post_title' => $postFieldProvider->get_title(),
            'post_excerpt' => $postFieldProvider->get_excerpt(),
            'post_type' => $template_post->post_type,
            'comment_status' => $template_post->comment_status,
            'ping_status' => $template_post->ping_status,
            'post_password' => $template_post->post_password,
            'post_parent' => $postFieldProvider->get_parent(),
            'post_mime_type' => $template_post->post_mime_type,
            'post_status' => $postFieldProvider->get_status($publish_datetime),
            'post_author' => $postFieldProvider->get_author($process_id)];

        if($publish_datetime) {
            $new_post['post_date'] = $publish_datetime;
            $new_post['post_date_gmt'] = get_gmt_from_date($publish_datetime);
        }

        if ($create_mode) {
            $new_post['post_name'] = $slug;
        }


        global $wpdb;
        $wpdb->query('START TRANSACTION');
        if ($create_mode) {
            $post_id = wp_insert_post($new_post, true);

        } else {
            $post_id = wp_update_post($new_post, true);
        };

        if (is_wp_error($post_id)) {
            error_log($post_id->get_error_message());
            $wpdb->query('ROLLBACK');
            delete_transient($transient_key);
            throw new Exception(json_encode($post_id->get_all_error_data()));
        }
        try {
            $result = $this->lpageryDao->lpagery_add_post_to_process($params, $post_id, $template_post->ID, $slug);
            if ($result["error"]) {
                error_log("LPagery Rolling Back Transaction During creation slug : $slug, Process : $process_id " . $result["error"]);
                $wpdb->query('ROLLBACK');
                delete_transient($transient_key);
                return new SavePageResult("ignored", $slug);
            }
            $created_process_post_id = $result["created_id"];
            $this->additionalDataSaver->saveAdditionalData($post_id, $template_post, $created_process_post_id, $params);

        } catch (Exception $e) {
            error_log("LPagery Rolling Back Transaction During creation slug : $slug, Process : $process_id " . $e->getMessage());
            $wpdb->query('ROLLBACK');
            delete_transient($transient_key);
            throw $e;
        }

        $wpdb->query('COMMIT');
        delete_transient($transient_key);

        return new SavePageResult($post_id_to_be_updated ? "updated" : "created", $slug);
    }


}