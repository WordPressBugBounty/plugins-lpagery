<?php

namespace LPagery\factories;

use LPagery\service\substitution\ImageSubstitutionHandler;
use LPagery\service\substitution\Spintax;
use LPagery\service\substitution\SubstitutionHandler;

class SubstitutionHandlerFactory
{
    public static function create(): SubstitutionHandler
    {
        return SubstitutionHandler::get_instance(Spintax::get_instance(),
            ImageSubstitutionHandler::get_instance());
    }

}
