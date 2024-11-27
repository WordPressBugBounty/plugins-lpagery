<?php

namespace LPagery\factories;

use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\FindPostService;
use LPagery\service\save_page\additional\AdditionalDataSaver;
use LPagery\service\save_page\additional\FifuHandler;
use LPagery\service\save_page\additional\MetaDataHandler;
use LPagery\service\save_page\additional\PagebuilderHandler;
use LPagery\service\save_page\additional\SeoPluginHandler;
use LPagery\service\save_page\additional\WpmlHandler;
use LPagery\service\save_page\PageSaver;
use LPagery\service\settings\SettingsController;
use LPagery\service\taxonomies\TaxonomySaveHandler;
use LPagery\data\LPageryDao;

class AdditionalDataSaverFactory
{
    public static function create(): AdditionalDataSaver
    {
        $substitutionHandler = SubstitutionHandlerFactory::create();

        $pagebuilderHandler = PagebuilderHandler::get_instance($substitutionHandler);
        $seoPluginHandler = SeoPluginHandler::get_instance($substitutionHandler);
        $wpmlHandler = WpmlHandler::get_instance();
        $fifuHandler = FifuHandler::get_instance();


        return AdditionalDataSaver::get_instance($pagebuilderHandler, $seoPluginHandler, $wpmlHandler,
            $fifuHandler, TaxonomySaveHandler::get_instance($substitutionHandler),
            MetaDataHandler::get_instance($substitutionHandler));

    }

}
