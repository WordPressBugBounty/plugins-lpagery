<?php

namespace LPagery\service\delete;

use Exception;
use LPagery\data\LPageryDao;

class DeletePageService
{
    private static ?DeletePageService $instance = null;
    private LPageryDao $lpageryDao;

    public function __construct(LPageryDao $lpageryDao)
    {
        $this->lpageryDao = $lpageryDao;
    }

    public static function getInstance(LPageryDao $lpageryDao): DeletePageService
    {
        if (self::$instance === null) {
            self::$instance = new DeletePageService($lpageryDao);
        }
        return self::$instance;
    }

    public function deletePages(array $post_ids)
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if(!$post){
                    continue;
                }

                do_action('before_delete_post', $post_id, $post);
                
                // Delete revisions
                $wpdb->delete(
                    $wpdb->posts,
                    array('post_parent' => $post_id, 'post_type' => 'revision')
                );
                
                // Delete the main post and related data
                $wpdb->delete($wpdb->posts, array('ID' => $post_id));
                $wpdb->delete($wpdb->postmeta, array('post_id' => $post_id));
                $wpdb->delete($wpdb->term_relationships, array('object_id' => $post_id));
                $this->lpageryDao->lpagery_delete_process_post($post_id);

                clean_post_cache($post_id);

                do_action('deleted_post', $post_id, $post);
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        // Clear various caches
        wp_cache_flush();
    }


}