<?php

namespace LPagery\factories;

use LPagery\io\Api;
use LPagery\data\LPageryDao;
use LPagery\service\sheet_sync\GoogleSheetQueueWorkerFactory;
use LPagery\service\sheet_sync\GoogleSheetSyncProcessHandler;
use LPagery\service\sheet_sync\GoogleSheetSyncPostDeleteHandler;

class GoogleSheetSyncProcessHandlerFactory
{
    public static function create(): GoogleSheetSyncProcessHandler
    {
        return GoogleSheetSyncProcessHandler::get_instance(
            Api::get_instance(),
            LPageryDao::get_instance(),
            GoogleSheetSyncPostDeleteHandler::get_instance(
                LPageryDao::get_instance(),
                InputParamProviderFactory::create(),
                SubstitutionHandlerFactory::create()
            ),
            DuplicateSlugHelperFactory::create(),
            GoogleSheetQueueWorkerFactory::create()
        );
    }
} 