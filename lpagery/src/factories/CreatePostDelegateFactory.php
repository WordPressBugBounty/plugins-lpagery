<?php

namespace LPagery\factories;

use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\FindPostService;
use LPagery\service\save_page\additional\AdditionalDataSaver;
use LPagery\service\save_page\additional\MetaDataHandler;
use LPagery\service\save_page\CreatePostDelegate;
use LPagery\service\save_page\additional\FifuHandler;
use LPagery\service\save_page\additional\PagebuilderHandler;
use LPagery\service\save_page\additional\SeoPluginHandler;
use LPagery\service\save_page\additional\WpmlHandler;
use LPagery\service\save_page\PageSaver;
use LPagery\service\save_page\update\PageUpdateDataHandler;
use LPagery\service\save_page\update\ShouldPageBeUpdatedChecker;
use LPagery\service\settings\SettingsController;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\service\taxonomies\TaxonomySaveHandler;
use LPagery\data\LPageryDao;

class CreatePostDelegateFactory
{
    public static function create(): CreatePostDelegate
    {
        $substitutionHandler = SubstitutionHandlerFactory::create();

        $settingsController = SettingsController::get_instance();
        $LPageryDao = LPageryDao::get_instance();
        $inputParamProvider = InputParamProviderFactory::create();


        $findPostService = FindPostService::get_instance($LPageryDao, $substitutionHandler);

        $dynamicPageAttributeHandler = DynamicPageAttributeHandler::get_instance(
            $settingsController,
            $LPageryDao,
            $findPostService
        );


        $additionalDataSaver = AdditionalDataSaverFactory::create();
        $pageCreator = PageSaver::get_instance(
            $LPageryDao,
            $additionalDataSaver
        );

        return CreatePostDelegate::get_instance(
            $LPageryDao,
            $inputParamProvider,
            $substitutionHandler,
            $dynamicPageAttributeHandler,
            $pageCreator,
            PageUpdateDataHandler::get_instance(
                $LPageryDao,
                $inputParamProvider,
                $substitutionHandler,
                $pageCreator,
                $dynamicPageAttributeHandler
            ),
            SubstitutionDataPreparator::get_instance(),ShouldPageBeUpdatedChecker::get_instance($settingsController, $LPageryDao),
        );
    }

}
