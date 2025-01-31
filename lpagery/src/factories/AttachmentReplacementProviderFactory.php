<?php

namespace LPagery\factories;

use LPagery\service\media\AttachmentHelper;
use LPagery\service\media\AttachmentReplacementProvider;
use LPagery\service\media\AttachmentSaver;
use LPagery\data\LPageryDao;
use LPagery\service\media\AttachmentSearchService;
use LPagery\service\media\CacheableAttachmentSearchService;

class AttachmentReplacementProviderFactory
{
    public static function create(): AttachmentReplacementProvider
    {
        $attachmentSearchService = CacheableAttachmentSearchService::get_instance(AttachmentSearchService::get_instance());
        $attachmentSaver =AttachmentSaver::get_instance($attachmentSearchService, SubstitutionHandlerFactory::create());
        $mediaHandler = AttachmentReplacementProvider::get_instance($attachmentSaver, AttachmentHelper::get_instance($attachmentSearchService));

        return $mediaHandler;
    }

}
