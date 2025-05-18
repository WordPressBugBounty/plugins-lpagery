<?php

namespace LPagery\controller;

use LPagery\data\LPageryDao;
use LPagery\data\SearchPostService;
use LPagery\io\Mapper;
use LPagery\wpml\WpmlHelper;

/**
 * Controller for handling post-related operations
 */
class PostController
{
    private static $instance;
    private SearchPostService $searchPostService;
    private LPageryDao $lpageryDao;
    private Mapper $mapper;

    /**
     * PostController constructor.
     *
     * @param SearchPostService $searchPostService
     * @param LPageryDao $lpageryDao
     * @param Mapper $mapper
     */
    public function __construct(SearchPostService $searchPostService, LPageryDao $lpageryDao, Mapper $mapper)
    {
        $this->searchPostService = $searchPostService;
        $this->lpageryDao = $lpageryDao;
        $this->mapper = $mapper;
    }

    /**
     * Singleton pattern implementation
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self(
                SearchPostService::get_instance(),
                LPageryDao::get_instance(),
                Mapper::get_instance()
            );
        }
        return self::$instance;
    }

    /**
     * Gets posts based on search criteria
     *
     * @param string $search Search term
     * @param array $custom_post_types Custom post types to include
     * @param string $mode Mode of operation
     * @param string $select Selection mode
     * @param int|null $template_id Template ID
     * @return array Array of mapped posts
     */
    public function getPosts(string $search, array $custom_post_types, string $mode, string $select, ?int $template_id = null): array
    {
        error_log(json_encode($custom_post_types));
        $posts = $this->searchPostService->lpagery_search_posts($search, $custom_post_types, $mode, $select, $template_id);
        return array_map([$this->mapper, 'lpagery_map_post'], $posts);
    }

    /**
     * Gets post type by post ID
     *
     * @param int $post_id Post ID
     * @return string Post type
     */
    public function getPostType(int $post_id): string
    {
        $post = get_post($post_id);
        return $post->post_type;
    }

    /**
     * Gets single post by ID with extended information
     *
     * @param int $post_id Post ID
     * @return array Post data
     */
    public function getPost(int $post_id): array
    {
        $WP_Post = get_post($post_id);
        if (!$WP_Post) {
            return ["found" => false];
        }
        
        $wpml_data = WpmlHelper::get_wpml_language_data($post_id);
        $array = [
            "title" => $WP_Post->post_title,
            "found" => true,
            "permalink" => get_permalink($post_id)
        ];
        
        if ($wpml_data->language_code) {
            $array["language_code"] = $wpml_data->language_code;
        }

        $post_type_object = get_post_type_object($WP_Post->post_type);

        if ($post_type_object->name) {
            // Get the original English labels
            $singular = $WP_Post->post_type === 'post' ? 'Post' : ($WP_Post->post_type === 'page' ? 'Page' : ucfirst(str_replace(['_', '-'], ' ', $post_type_object->name)));
            $plural = $WP_Post->post_type === 'post' ? 'Posts' : ($WP_Post->post_type === 'page' ? 'Pages' : ucfirst(str_replace(['_', '-'], ' ', $post_type_object->name)) . 's');

            $post_type_array = [
                "name" => $post_type_object->name,
                "singular" => $singular,
                "plural" => $plural,
                "hierarchical" => $post_type_object && is_post_type_hierarchical($WP_Post->post_type)
            ];
            
            $array["type"] = $post_type_array;
        }

        return $array;
    }

    /**
     * Gets template posts
     *
     * @return array Template posts
     */
    public function getTemplatePosts(): array
    {
        $template_posts = $this->lpageryDao->lpagery_get_template_posts();
        
        if ($this->hasWpmlSupport()) {
            foreach ($template_posts as &$post_array) {
                $wpmlData = WpmlHelper::get_wpml_language_data($post_array->id);
                if ($wpmlData->language_code) {
                    $post_array->language_code = $wpmlData->language_code;
                }
            }
        }
        
        return $template_posts;
    }

    /**
     * Check if WPML support is available
     * 
     * @return bool Whether WPML support is available
     */
    protected function hasWpmlSupport(): bool
    {
        return function_exists('wpml_get_language_information');
    }
} 