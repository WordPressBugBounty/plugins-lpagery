<?php

namespace LPagery\service\preparation;

use LPagery\model\BaseParams;
use LPagery\service\media\AttachmentHelper;
use LPagery\service\media\AttachmentReplacementProvider;
use LPagery\utils\Utils;
use LPagery\wpml\WpmlHelper;
class InputParamMediaProvider {
    public static ?InputParamMediaProvider $instance = null;

    private AttachmentReplacementProvider $attachmentReplacementProvider;

    private AttachmentHelper $attachmentHelper;

    public function __construct( AttachmentReplacementProvider $attachmentReplacementProvider, AttachmentHelper $attachmentHelper ) {
        $this->attachmentReplacementProvider = $attachmentReplacementProvider;
        $this->attachmentHelper = $attachmentHelper;
    }

    public static function get_instance( AttachmentReplacementProvider $mediaHandler, AttachmentHelper $attachmentHelper ) : InputParamMediaProvider {
        if ( null === self::$instance ) {
            self::$instance = new self($mediaHandler, $attachmentHelper);
        }
        return self::$instance;
    }

    public function provideMediaParams( BaseParams $params, $source_post_id ) : array {
        return array();
    }

    private function add_image_replacements( array $size_replacements, array $keys, array $values ) : array {
        $source_srcsets = $size_replacements["source_srcsets"];
        $target_srcsets = $size_replacements["target_srcsets"];
        $keys = array_merge( $keys, $source_srcsets );
        $values = array_merge( $values, $target_srcsets );
        $source_urls = $size_replacements["source_urls"];
        $target_urls = $size_replacements["target_urls"];
        $keys = array_merge( $keys, $source_urls );
        $values = array_merge( $values, $target_urls );
        return array($keys, $values);
    }

}
