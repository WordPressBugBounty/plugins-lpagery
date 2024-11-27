<?php

namespace LPagery\factories;

use LPagery\service\duplicates\DuplicateSlugHelper;
use LPagery\service\duplicates\DuplicateSlugProvider;
use LPagery\service\settings\SettingsController;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\data\LPageryDao;

class DuplicateSlugHandlerFactory
{
    public static function create(): DuplicateSlugProvider
    {
        $substitutionHandler = SubstitutionHandlerFactory::create();

        $LPageryDao = LPageryDao::get_instance();
        $inputParamProvider = InputParamProviderFactory::create();

        $duplicateSlugHelper = DuplicateSlugHelper::get_instance($inputParamProvider, $substitutionHandler);

        $preparator = SubstitutionDataPreparator::get_instance();

        return DuplicateSlugProvider::get_instance(
            $preparator,
            $LPageryDao,
            $duplicateSlugHelper
        );
    }

}
