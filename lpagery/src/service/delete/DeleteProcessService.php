<?php

namespace LPagery\service\delete;

use Exception;
use LPagery\data\LPageryDao;

class DeleteProcessService
{
    private static ?DeleteProcessService $instance = null;
    private LPageryDao $lpageryDao;
    private DeletePageService $deletePageService;

    public function __construct(LPageryDao $lpageryDao, DeletePageService $deletePageService)
    {
        $this->lpageryDao = $lpageryDao;
        $this->deletePageService = $deletePageService;
    }

    public static function getInstance(LPageryDao $lpageryDao, DeletePageService $deletePageService): DeleteProcessService
    {
        if (self::$instance === null) {
            self::$instance = new DeleteProcessService($lpageryDao, $deletePageService);
        }
        return self::$instance;
    }

    public function deleteProcess(int $processId, bool $delete_posts)
    {
        if ($delete_posts) {
            $posts = $this->lpageryDao->lpagery_get_posts_by_process($processId);
            $post_ids = array_map(function($post) {
                return $post->id;
            }, $posts);
            if(!empty($post_ids)){
                $this->deletePageService->deletePages($post_ids);
            }
        }
        $this->lpageryDao->lpagery_delete_process($processId);
    }

}