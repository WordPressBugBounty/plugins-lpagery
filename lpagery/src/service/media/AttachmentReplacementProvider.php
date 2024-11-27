<?php

namespace LPagery\service\media;

use LPagery\model\BaseParams;
class AttachmentReplacementProvider {
    private static $instance;

    private AttachmentSaver $attachmentSaver;

    private AttachmentHelper $attachmentHelper;

    public function __construct( AttachmentSaver $attachmentSaver, AttachmentHelper $attachmentHelper ) {
        $this->attachmentSaver = $attachmentSaver;
        $this->attachmentHelper = $attachmentHelper;
    }

    public static function get_instance( AttachmentSaver $attachmentSaver, AttachmentHelper $attachmentHelper ) {
        if ( null === self::$instance ) {
            self::$instance = new self($attachmentSaver, $attachmentHelper);
        }
        return self::$instance;
    }

    public function lpagery_get_attachment_size_replacements_relative( $source_id, $target_id ) {
        $result = self::lpagery_get_attachment_size_replacements( $source_id, $target_id );
        array_walk_recursive( $result, function ( &$value ) {
            $value = $this->attachmentHelper->lpagery_make_image_url_relative( $value );
        } );
        return $result;
    }

    public function lpagery_get_attachment_size_replacements( $source_id, $target_id ) {
        $source_metadata = wp_get_attachment_metadata( $source_id );
        $target_metadata = wp_get_attachment_metadata( $target_id );
        $source_sizes = $source_metadata["sizes"] ?? array();
        $target_sizes = $target_metadata["sizes"] ?? array();
        if ( isset( $source_metadata["width"] ) && isset( $source_metadata["height"] ) && isset( $target_metadata["width"] ) && isset( $target_metadata["height"] ) ) {
            $source_sizes[] = array(
                "width"  => $source_metadata["width"],
                "height" => $source_metadata["height"],
            );
            $target_sizes[] = array(
                "width"  => $target_metadata["width"],
                "height" => $target_metadata["height"],
            );
        }
        usort( $source_sizes, function ( $a, $b ) {
            return ( $a["width"] >= $b["width"] ? -1 : 1 );
        } );
        usort( $target_sizes, function ( $a, $b ) {
            return ( $a["width"] >= $b["width"] ? -1 : 1 );
        } );
        $source_src_sets = array();
        $target_src_sets = array();
        $source_urls = array();
        $target_urls = array();
        foreach ( $source_sizes as $index => $source_size ) {
            $source_src_sets[] = $this->attachmentHelper->get_src_set_url( $source_id, $source_size, true );
            $source_urls[] = $this->attachmentHelper->get_src_set_url( $source_id, $source_size, true );
            $target_size = current( array_filter( $target_sizes, function ( $element ) use($source_size) {
                return $element["width"] <= $source_size["width"];
            } ) );
            if ( !$target_size ) {
                $target_size = $target_sizes[0];
            }
            if ( isset( $target_size ) ) {
                $target_srcset_url = $this->attachmentHelper->get_src_set_url( $target_id, $target_size );
            } else {
                $target_srcset_url = "";
                $target_size = end( $target_sizes );
            }
            $target_urls[] = $this->attachmentHelper->get_src_set_url( $target_id, $target_size );
            $target_src_sets[] = $target_srcset_url;
        }
        return array(
            "source_srcsets" => $source_src_sets,
            "target_srcsets" => $target_src_sets,
            "source_urls"    => $source_urls,
            "target_urls"    => $target_urls,
        );
    }

    public function lpagery_copy_download_or_get_image( $source_attachment, $name, BaseParams $params ) : ?array {
        return null;
    }

}
