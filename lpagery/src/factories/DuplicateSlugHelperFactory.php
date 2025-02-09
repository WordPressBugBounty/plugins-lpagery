<?php

namespace LPagery\factories;

use LPagery\data\LPageryDao;
use LPagery\service\duplicates\DuplicateSlugHelper;
use LPagery\service\duplicates\DuplicateSlugProvider;
use LPagery\service\substitution\SubstitutionDataPreparator;

class DuplicateSlugHelperFactory
{
    public static function create(): DuplicateSlugHelper
    {
        $substitutionHandler = SubstitutionHandlerFactory::create();

        $inputParamProvider = InputParamProviderFactory::create();

        return DuplicateSlugHelper::get_instance($inputParamProvider, $substitutionHandler, DynamicPageAttributeHandlerFactory::create());
    }

}
