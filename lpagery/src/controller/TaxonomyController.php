<?php

namespace LPagery\controller;

/**
 * Controller for handling taxonomy-related operations
 */
class TaxonomyController
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
     * Gets taxonomy terms
     *
     * @return array Taxonomy terms organized by taxonomy
     */
    public function getTaxonomyTerms(): array
    {
        $categories = get_terms(array('hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'));

        $result = [];
        foreach ($categories as $category) {
            // Only add unique term IDs
            if (!isset($result[$category->taxonomy][$category->term_id])) {
                $result[$category->taxonomy][$category->term_id] = [
                    "id" => $category->term_id,
                    "name" => $category->name
                ];
            }
        }

        // Reformat result to remove keys as term IDs
        foreach ($result as $taxonomy => $terms) {
            $result[$taxonomy] = array_values($terms);
        }

        return $result;
    }

    /**
     * Gets taxonomies for a specific post type or all taxonomies
     *
     * @param string|null $post_type Post type to get taxonomies for
     * @return array Array of taxonomies
     */
    public function getTaxonomies(?string $post_type = null): array
    {
        if (!$post_type) {
            $taxonomies = get_taxonomies(array(), 'objects');
        } else {
            $taxonomies = get_object_taxonomies($post_type, 'objects');
        }

        $result = array_map(function ($taxonomy) {
            return [
                "name" => $taxonomy->name,
                "label" => $taxonomy->label != null ? $taxonomy->label : $taxonomy->name
            ];
        }, $taxonomies);
        
        return array_values($result);
    }
} 