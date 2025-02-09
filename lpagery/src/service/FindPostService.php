<?php

namespace LPagery\service;

use LPagery\service\substitution\SubstitutionHandler;
use LPagery\data\LPageryDao;
use LPagery\model\BaseParams;

class FindPostService
{
    private static ?FindPostService $instance = null;
    private LPageryDao $lpageryDao;
    private SubstitutionHandler $substitutionHandler;
    private array $cache = [];

    public function __construct(LPageryDao $lpageryDao, SubstitutionHandler $substitutionHandler)
    {
        $this->lpageryDao = $lpageryDao;
        $this->substitutionHandler = $substitutionHandler;
        $this->cache = [];
    }

    public static function get_instance(LPageryDao $lpageryDao, SubstitutionHandler $substitutionHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($lpageryDao, $substitutionHandler);
        }
        return self::$instance;
    }

    public function lpagery_find_post_or_default(BaseParams $params, $lpagery_post_term, $lpagery_post_id_from_dashboard, $post_type)
    {
        $lpagery_post_term = $this->substitutionHandler->lpagery_substitute($params, $lpagery_post_term);

        if (is_numeric($lpagery_post_term)) {
            $found_post = $this->findPostByIdWithCache($lpagery_post_term);
            if ($found_post) {
                return $found_post;
            }
        }

        if (!$lpagery_post_term) {
            return $this->findPostByIdWithCache($lpagery_post_id_from_dashboard);
        }

        $lpagery_post_term = sanitize_title($lpagery_post_term);
        $found_post = $this->findPostByNameAndTypeWithCache($lpagery_post_term, $post_type);
        
        return $found_post ?? $this->findPostByIdWithCache($lpagery_post_id_from_dashboard);
    }

    public function lpagery_find_post(BaseParams $params, $lpagery_post_term, $post_type)
    {
        $lpagery_post_term = $this->substitutionHandler->lpagery_substitute($params, $lpagery_post_term);

        if (is_numeric($lpagery_post_term)) {
            $found_post = $this->findPostByIdWithCache($lpagery_post_term);
            if ($found_post) {
                return $found_post;
            }
        }

        $lpagery_post_term = sanitize_title($lpagery_post_term);
        return $this->findPostByNameAndTypeWithCache($lpagery_post_term, $post_type);
    }


    private function getCacheKey($term, $post_type): string
    {
        return $term . '_' . $post_type;
    }

    private function findPostByIdWithCache($post_id)
    {
        $cache_key = $this->getCacheKey($post_id, 'id');
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $found_post = $this->lpageryDao->lpagery_find_post_by_id($post_id);
        if ($found_post) {
            $this->cache[$cache_key] = $found_post;
        }
        return $found_post;
    }

    private function findPostByNameAndTypeWithCache($term, $post_type)
    {
        $cache_key = $this->getCacheKey($term, $post_type);
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $result = $this->lpageryDao->lpagery_find_post_by_name_and_type_equal($term, $post_type);
        $this->cache[$cache_key] = $result;
        return $result;
    }

}