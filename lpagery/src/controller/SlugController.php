<?php

namespace LPagery\controller;

use LPagery\utils\Utils;

/**
 * Controller for handling slug-related operations
 */
class SlugController
{
    private static $instance;

    /**
     * Singleton pattern implementation
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Sanitizes a slug and returns it with the full URL
     *
     * @param string $slug The slug to sanitize
     * @param int $parent_id The parent post ID
     * @param int $template_id The template post ID
     * @return array Array containing the URL
     */
    public function sanitizeSlug(string $slug, int $parent_id = 0, int $template_id = 0): array
    {
        if (empty($slug)) {
            return ["url" => ""];
        }

        $slug = strtolower(Utils::lpagery_sanitize_title_with_dashes($slug));
        $base_url = '';

        // Get post type slug if template exists
        if ($template_id > 0) {
            $post_type = get_post_type($template_id);
            $post_type_obj = get_post_type_object($post_type);
            if ($post_type_obj) {
                // First try to get the rewrite slug if set
                if (isset($post_type_obj->rewrite['slug'])) {
                    $base_url .= $post_type_obj->rewrite['slug'] . '/';
                } // If no rewrite slug and it's not 'post' or 'page', use the post type name
                else if (!in_array($post_type, ['post', 'page'])) {
                    $base_url .= $post_type . '/';
                }
            }
        }

        // Add parent path if parent exists
        if ($parent_id) {
            $parent_path = trim(get_page_uri($parent_id), '/');
            if ($parent_path) {
                $base_url .= $parent_path . '/';
            }
        }

        // Combine everything for the final URL
        $final_url = site_url($base_url . $slug);

        return ["url" => $final_url];
    }

    /**
     * Custom sanitizes a title
     *
     * @param string $slug The slug to sanitize
     * @return string The sanitized slug
     */
    public function customSanitizeTitle(string $slug): string
    {
        if (empty($slug)) {
            return '';
        }
        
        return strtolower(urldecode(Utils::lpagery_sanitize_title_with_dashes($slug)));
    }

    /**
     * Gets a post title as a slug
     *
     * @param int $post_id The post ID
     * @return array Array containing the slug
     */
    public function getPostTitleAsSlug(int $post_id): array
    {
        $post = get_post($post_id);
        $slug = strtolower(Utils::lpagery_sanitize_title_with_dashes($post->post_title));
        
        return ["slug" => $slug];
    }
} 