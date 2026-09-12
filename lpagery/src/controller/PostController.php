<?php

namespace LPagery\controller;

use LPagery\data\repository\PageSetRepository;
use LPagery\data\SearchPostService;
use LPagery\io\Mapper;

/**
 * Controller for handling post-related operations
 */
class PostController
{
    private SearchPostService $searchPostService;
    private PageSetRepository $pageSetRepository;
    private Mapper $mapper;

    /**
     * PostController constructor.
     *
     * @param SearchPostService $searchPostService
     * @param PageSetRepository $pageSetRepository
     * @param Mapper $mapper
     */
    public function __construct(SearchPostService $searchPostService, PageSetRepository $pageSetRepository, Mapper $mapper)
    {
        $this->searchPostService = $searchPostService;
        $this->pageSetRepository = $pageSetRepository;
        $this->mapper = $mapper;
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
        
        $array = [
            "title" => $WP_Post->post_title,
            "found" => true,
            "permalink" => get_permalink($post_id)
        ];

        $array = array_merge($array, $this->mapper->language_payload($post_id));

        $post_type_object = get_post_type_object($WP_Post->post_type);

        if ($post_type_object->name) {
            $singular = $post_type_object->labels->singular_name ?? ($WP_Post->post_type === 'post' ? 'Post' : ($WP_Post->post_type === 'page' ? 'Page' : ucfirst(str_replace(['_', '-'], ' ', $post_type_object->name))));

            $plural = $post_type_object->labels->name ?? ($WP_Post->post_type === 'post' ? 'Posts' : ($WP_Post->post_type === 'page' ? 'Pages' : ucfirst(str_replace(['_', '-'], ' ', $post_type_object->name)) . 's'));

            if($WP_Post->post_type === 'post' ) {
                $singular = "Post";
                $plural = "Posts";
            }
            if($WP_Post->post_type === 'page' ) {
                $singular = "Page";
                $plural = "Pages";
            }
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
        $template_posts = $this->pageSetRepository->get_template_posts();
        
        foreach ($template_posts as $post_array) {
            foreach ($this->mapper->language_payload((int)$post_array->id) as $field => $value) {
                $post_array->$field = $value;
            }
        }
        
        return $template_posts;
    }
}
