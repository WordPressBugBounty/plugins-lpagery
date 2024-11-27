<?php

namespace LPagery\factories;

use LPagery\service\save_page\PageSaver;
use LPagery\service\save_page\update\PageUpdateDataHandler;
use LPagery\data\LPageryDao;

class PageUpdateDataHandlerFactory
{
    public static function create(): PageUpdateDataHandler
    {
        $substitutionHandler = SubstitutionHandlerFactory::create();
        $LPageryDao = LPageryDao::get_instance();
        $inputParamProvider = InputParamProviderFactory::create();
        $additionalDataSaver = AdditionalDataSaverFactory::create();

        $pageSaver = PageSaver::get_instance($LPageryDao, $additionalDataSaver);
        return PageUpdateDataHandler::get_instance($LPageryDao, $inputParamProvider, $substitutionHandler, $pageSaver,
            DynamicPageAttributeHandlerFactory::create());
    }

}
