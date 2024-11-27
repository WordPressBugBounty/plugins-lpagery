<?php

namespace LPagery\factories;

use LPagery\service\media\AttachmentHelper;
use LPagery\service\media\AttachmentReplacementProvider;
use LPagery\service\media\AttachmentSaver;
use LPagery\service\preparation\InputParamMediaProvider;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\settings\SettingsController;
use LPagery\data\LPageryDao;

class InputParamProviderFactory
{
    /**
     * @return InputParamProvider
     */
    public static function create(): InputParamProvider
    {
        $substitutionHandler = SubstitutionHandlerFactory::create();
        $LPageryDao = LPageryDao::get_instance();
        $attachmentHelper = AttachmentHelper::get_instance($LPageryDao);
        $attachmentSaver = AttachmentSaver::get_instance($LPageryDao, $substitutionHandler);
        $inputParamMediaProvider = InputParamMediaProvider::get_instance(AttachmentReplacementProvider::get_instance(
            $attachmentSaver, $attachmentHelper), $attachmentHelper);
        return InputParamProvider::get_instance(SettingsController::get_instance(),$inputParamMediaProvider);
    }

}
