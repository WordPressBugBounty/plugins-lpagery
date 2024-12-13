<?php

namespace LPagery\factories;

use LPagery\service\InstallationDateHandler;
use LPagery\service\media\AttachmentHelper;
use LPagery\service\media\AttachmentReplacementProvider;
use LPagery\service\media\AttachmentSaver;
use LPagery\service\preparation\InputParamMediaProvider;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\settings\SettingsController;
use LPagery\data\LPageryDao;
class InputParamProviderFactory {
    /**
     * @return InputParamProvider
     */
    public static function create() : InputParamProvider {
        $inputParamMediaProvider = null;
        return InputParamProvider::get_instance( SettingsController::get_instance(), InstallationDateHandler::get_instance(), $inputParamMediaProvider );
    }

}
