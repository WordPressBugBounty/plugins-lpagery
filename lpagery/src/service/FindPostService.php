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

    public function __construct(LPageryDao $lpageryDao, SubstitutionHandler $substitutionHandler)
    {
        $this->lpageryDao = $lpageryDao;
        $this->substitutionHandler = $substitutionHandler;
    }

    public static function get_instance(LPageryDao $lpageryDao, SubstitutionHandler $substitutionHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($lpageryDao, $substitutionHandler);
        }
        return self::$instance;
    }


    public function lpagery_find_post(BaseParams $params, $lpagery_post_term, $lpagery_post_id_from_dashboard, $post_type)
    {
        $lpagery_post_term = $this->substitutionHandler->lpagery_substitute($params, $lpagery_post_term);

        if (is_numeric($lpagery_post_term)) {
            $found_post = $this->lpageryDao->lpagery_find_post_by_id($lpagery_post_term);
            if ($found_post) {
                return $found_post;
            }
        }

        if (!$lpagery_post_term) {
            return $this->lpageryDao->lpagery_find_post_by_id($lpagery_post_id_from_dashboard);
        }

        $lpagery_post_term = sanitize_title($lpagery_post_term);

        $found_post = $this->lpageryDao->lpagery_find_post_by_name_and_type_equal($lpagery_post_term, $post_type);


        return $found_post ?? $this->lpageryDao->lpagery_find_post_by_id($lpagery_post_id_from_dashboard);
    }
}