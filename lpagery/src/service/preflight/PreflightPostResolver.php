<?php

namespace LPagery\service\preflight;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Resolves the distinct `lpagery_parent` and `lpagery_template` values of a sheet to post ids, with
 * the rules the run applies to each row ({@see \LPagery\service\FindPostService::lpagery_find_post_or_default()}):
 * a URL names its post, a number is a post id, anything else is a slug of the same post type. A value
 * that resolves to nothing keeps the configured post, so it is left out of the result.
 *
 * One id lookup and one slug lookup for the whole set, however many rows share a value.
 */
class PreflightPostResolver
{
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(GeneratedPageRepository $generatedPageRepository)
    {
        $this->generatedPageRepository = $generatedPageRepository;
    }

    /**
     * Parents exist only for a hierarchical post type. For any other the run ignores `lpagery_parent`.
     *
     * @param string[] $terms distinct, already substituted values
     * @return array<string, int> term => parent id, for the terms that resolve
     */
    public function resolve_parents(array $terms, string $post_type): array
    {
        return $this->ids($this->resolve_parent_posts($terms, $post_type));
    }

    /**
     * @param string[] $terms distinct, already substituted values
     * @return array<string, array{id: int, title: string}> term => parent post, for the terms that resolve
     */
    public function resolve_parent_posts(array $terms, string $post_type): array
    {
        if (empty($terms) || !is_post_type_hierarchical($post_type)) {
            return [];
        }
        return $this->resolve_posts($terms, $post_type);
    }

    /**
     * @param string[] $terms distinct, already substituted values
     * @return array<string, int> term => post id, for the terms that resolve
     */
    public function resolve(array $terms, string $post_type): array
    {
        return $this->ids($this->resolve_posts($terms, $post_type));
    }

    /**
     * @param string[] $terms distinct, already substituted values
     * @return array<string, array{id: int, title: string}> term => post, for the terms that resolve
     */
    public function resolve_posts(array $terms, string $post_type): array
    {
        if (empty($terms)) {
            return [];
        }

        $ids_by_term = [];
        $names_by_term = [];
        foreach ($terms as $term) {
            if (filter_var($term, FILTER_VALIDATE_URL)) {
                $post_id = (int)url_to_postid($term);
                if ($post_id) {
                    $ids_by_term[$term] = $post_id;
                    continue;
                }
            }
            if (is_numeric($term)) {
                $ids_by_term[$term] = (int)$term;
                continue;
            }
            $names_by_term[$term] = sanitize_title($term);
        }

        $posts = [];
        $titles_by_id = empty($ids_by_term) ? [] : $this->generatedPageRepository->find_post_titles_by_ids(array_values(array_unique($ids_by_term)));
        foreach ($ids_by_term as $term => $post_id) {
            if (isset($titles_by_id[$post_id])) {
                $posts[$term] = ['id' => $post_id, 'title' => $titles_by_id[$post_id]];
            } elseif (is_numeric($term)) {
                // A number that is no post id may still be a slug.
                $names_by_term[$term] = sanitize_title((string)$term);
            }
        }

        if (!empty($names_by_term)) {
            $posts_by_name = $this->generatedPageRepository->find_posts_by_names(array_values(array_unique($names_by_term)), $post_type);
            foreach ($names_by_term as $term => $name) {
                if (isset($posts_by_name[$name])) {
                    $posts[$term] = $posts_by_name[$name];
                }
            }
        }

        return $posts;
    }

    /**
     * @param array<string, array{id: int, title: string}> $posts
     * @return array<string, int>
     */
    private function ids(array $posts): array
    {
        return array_map(function ($post) {
            return $post['id'];
        }, $posts);
    }
}
