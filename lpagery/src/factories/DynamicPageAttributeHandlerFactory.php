<?php

namespace LPagery\factories;

use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\FindPostService;
use LPagery\service\settings\SettingsController;
use LPagery\data\LPageryDao;

class DynamicPageAttributeHandlerFactory
{
    public static function create(): DynamicPageAttributeHandler
    {
        $substitutionHandler = SubstitutionHandlerFactory::create();
        $LPageryDao = LPageryDao::get_instance();
        $findPostService = FindPostService::get_instance($LPageryDao, $substitutionHandler);
        return DynamicPageAttributeHandler::get_instance(SettingsController::get_instance(), $LPageryDao, $findPostService);
    }

}
