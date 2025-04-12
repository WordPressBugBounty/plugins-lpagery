<?php

namespace LPagery\factories;

use LPagery\service\media\AttachmentHelper;
use LPagery\service\media\AttachmentMetadataUpdater;
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
        $substitutionHandler = SubstitutionHandlerFactory::create();
        $attachmentSaver =AttachmentSaver::get_instance($attachmentSearchService, $substitutionHandler);
        $attachmentMetadataUpdater = AttachmentMetadataUpdater::get_instance($substitutionHandler   );
        $mediaHandler = AttachmentReplacementProvider::get_instance($attachmentSaver, AttachmentHelper::get_instance($attachmentSearchService), $attachmentMetadataUpdater);

        return $mediaHandler;
    }

}
