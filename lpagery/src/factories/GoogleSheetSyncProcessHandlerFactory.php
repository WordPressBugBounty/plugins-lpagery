<?php

namespace LPagery\factories;

use LPagery\io\Api;
use LPagery\data\LPageryDao;
use LPagery\service\delete\DeletePageService;
use LPagery\service\sheet_sync\GoogleSheetQueueWorkerFactory;
use LPagery\service\sheet_sync\GoogleSheetSyncProcessHandler;
use LPagery\service\sheet_sync\GoogleSheetSyncPostDeleteHandler;
use service\delete\DeletePageServiceTest;

class GoogleSheetSyncProcessHandlerFactory
{
    public static function create(): GoogleSheetSyncProcessHandler
    {
        $LPageryDao = LPageryDao::get_instance();
        return GoogleSheetSyncProcessHandler::get_instance(
            Api::get_instance(), $LPageryDao,
            GoogleSheetSyncPostDeleteHandler::get_instance(
                $LPageryDao,
                InputParamProviderFactory::create(),
                SubstitutionHandlerFactory::create(),
                DeletePageService::getInstance($LPageryDao)
            ),
            DuplicateSlugHelperFactory::create(),
            DynamicPageAttributeHandlerFactory::create(),
            InputParamProviderFactory::create()
        );
    }
} 