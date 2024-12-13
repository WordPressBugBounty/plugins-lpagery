<?php

namespace LPagery\service;

use LPagery\data\LPageryDao;
use ZipArchive;

class PageExportHandler
{
    private static $instance;

    private function __construct()
    {
        // Initialization code here
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }


    public function export($process_id)
    {
        $process_by_id = LPageryDao::get_instance()->lpagery_get_process_by_id($process_id);
        $post_id = $process_by_id->post_id;

        // Ensure the post ID is valid
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        // Prepare post data
        $post_data = array(
            'ID' => $post->ID,
            'post_author' => $post->post_author,
            'post_date' => $post->post_date,
            'post_content' => ($post->post_content),
            'post_content_filtered' => ($post->post_content_filtered),
            'post_title' => $post->post_title,
            'post_excerpt' => $post->post_excerpt,
            'post_status' => $post->post_status,
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'post_name' => $post->post_name,
            'post_modified' => $post->post_modified,
            'post_parent' => $post->post_parent,
            'menu_order' => $post->menu_order,
            'post_type' => $post->post_type,
            'post_mime_type' => $post->post_mime_type,
            'comment_count' => $post->comment_count,
        );

        // Get post metadata
        $post_meta = get_post_meta($post_id);


        // JSON encode the data
        $json_data_post = json_encode($post_data, JSON_PRETTY_PRINT);
        $json_data_meta = json_encode($post_meta, JSON_PRETTY_PRINT);

        // Create a temporary file for the ZIP archive
        $temp_file = tempnam(sys_get_temp_dir(), 'zip');
        unlink($temp_file);
        // Create the ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($temp_file, ZipArchive::CREATE) !== true) {
            return '';
        }

        // Add the JSON data to the ZIP archive
        $zip->addFromString('post_data.json', $json_data_post);
        $zip->addFromString('post_meta.json', $json_data_meta);
        $zip->close();

        // Read the ZIP file contents into memory
        $zip_data = file_get_contents($temp_file);

        // Delete the temporary file
        unlink($temp_file);

        // Return the ZIP file as a download response
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="exported_post_data.zip"');
        header('Content-Length: ' . strlen($zip_data));
        echo $zip_data;

        exit;
    }

}
