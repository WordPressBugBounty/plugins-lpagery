<?php

namespace LPagery\factories;

use LPagery\service\media\AttachmentHelper;
use LPagery\service\media\AttachmentReplacementProvider;
use LPagery\service\media\AttachmentSaver;
use LPagery\data\LPageryDao;

class AttachmentReplacementProviderFactory
{
    public static function create(): AttachmentReplacementProvider
    {
        $LPageryDao = LPageryDao::get_instance();
        $attachmentSaver =AttachmentSaver::get_instance($LPageryDao, SubstitutionHandlerFactory::create());
        $mediaHandler = AttachmentReplacementProvider::get_instance($attachmentSaver, AttachmentHelper::get_instance($LPageryDao));

        return $mediaHandler;
    }

}
