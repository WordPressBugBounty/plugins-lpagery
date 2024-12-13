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

        $LPageryDao = LPageryDao::get_instance();

        $duplicateSlugHelper = DuplicateSlugHelperFactory::create();

        $preparator = SubstitutionDataPreparator::get_instance();

        return DuplicateSlugProvider::get_instance(
            $preparator,
            $LPageryDao,
            $duplicateSlugHelper
        );
    }

}
