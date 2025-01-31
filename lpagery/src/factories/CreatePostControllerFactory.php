<?php

namespace LPagery\factories;

use LPagery\controller\CreatePostController;
use LPagery\data\LPageryDao;
use LPagery\service\media\AttachmentSearchService;
use LPagery\service\media\CacheableAttachmentSearchService;
use LPagery\service\settings\SettingsController;
class CreatePostControllerFactory {
    public static function create() : CreatePostController {
        $createPostDelegate = CreatePostDelegateFactory::create();
        $LPageryDao = LPageryDao::get_instance();
        $settingsController = SettingsController::get_instance();
        $cacheableAttachmentSearchService = null;
        return CreatePostController::get_instance(
            $createPostDelegate,
            $LPageryDao,
            $settingsController,
            $cacheableAttachmentSearchService
        );
    }

}
