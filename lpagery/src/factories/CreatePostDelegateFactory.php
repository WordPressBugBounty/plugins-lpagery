<?php

namespace LPagery\factories;

use LPagery\data\LPageryDao;
use LPagery\service\caching\PurgeCachingPluginsService;
use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\FindPostService;
use LPagery\service\save_page\CreatePostDelegate;
use LPagery\service\save_page\PageSaver;
use LPagery\service\save_page\update\PageUpdateDataHandler;
use LPagery\service\save_page\update\ShouldPageBeUpdatedChecker;
use LPagery\service\settings\SettingsController;
use LPagery\service\substitution\SubstitutionDataPreparator;
class CreatePostDelegateFactory {
    public static function create() : CreatePostDelegate {
        $substitutionHandler = SubstitutionHandlerFactory::create();
        $settingsController = SettingsController::get_instance();
        $LPageryDao = LPageryDao::get_instance();
        $inputParamProvider = InputParamProviderFactory::create();
        $findPostService = FindPostService::get_instance( $LPageryDao, $substitutionHandler );
        $dynamicPageAttributeHandler = DynamicPageAttributeHandler::get_instance( $settingsController, $LPageryDao, $findPostService );
        $additionalDataSaver = AdditionalDataSaverFactory::create();
        $shouldPageBeUpdatedChecker = null;
        $pageUpdateDataHandler = null;
        $purgeCachingPluginsService = PurgeCachingPluginsService::get_instance();
        $pageCreator = PageSaver::get_instance(
            $LPageryDao,
            $additionalDataSaver,
            $shouldPageBeUpdatedChecker,
            $purgeCachingPluginsService
        );
        return CreatePostDelegate::get_instance(
            $LPageryDao,
            $inputParamProvider,
            $substitutionHandler,
            $dynamicPageAttributeHandler,
            $pageCreator,
            $pageUpdateDataHandler,
            SubstitutionDataPreparator::get_instance()
        );
    }

}
