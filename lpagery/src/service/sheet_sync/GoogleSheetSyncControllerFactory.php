<?php

namespace LPagery\service\sheet_sync;

use LPagery\service\settings\SettingsController;
use LPagery\data\LPageryDao;
use LPagery\factories\CreatePostDelegateFactory;
use LPagery\factories\InputParamProviderFactory;
use LPagery\factories\SubstitutionHandlerFactory;
use LPagery\io\Api;

class GoogleSheetSyncControllerFactory
{

    public static function create(): GoogleSheetSyncController
    {
        $googleSheetSyncPostSaveDelegate = GoogleSheetSyncPostSaveDelegate::get_instance(CreatePostDelegateFactory::create());

        $googleSheetSyncPostDeleteHandler = GoogleSheetSyncPostDeleteHandler::get_instance(LPageryDao::get_instance(),
            InputParamProviderFactory::create(), SubstitutionHandlerFactory::create());
        $googleSheetSyncProcessHandler = GoogleSheetSyncProcessHandler::get_instance(Api::get_instance(),
            LPageryDao::get_instance(), $googleSheetSyncPostSaveDelegate, $googleSheetSyncPostDeleteHandler);

        return GoogleSheetSyncController::get_instance($googleSheetSyncProcessHandler, LPageryDao::get_instance(),
            SettingsController::get_instance());
    }
}