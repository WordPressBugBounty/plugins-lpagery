<?php

namespace LPagery\service\media;

use LPagery\data\LPageryDao;
class AttachmentHelper {
    private static $instance;

    private $lpageryDao;

    public function __construct( LPageryDao $lpageryDao ) {
        $this->lpageryDao = $lpageryDao;
    }

    public static function get_instance( LPageryDao $lpageryDao ) {
        if ( null === self::$instance ) {
            self::$instance = new self($lpageryDao);
        }
        return self::$instance;
    }

    public function lpagery_get_attachment( $name ) {
        $attachments = $this->lpagery_get_attachments( $name );
        return ( empty( $attachments ) ? null : (array) $attachments[0] );
    }

    public function lpagery_get_attachments( $name ) {
        return null;
    }

    /**
     * @param $name
     * @return array|string|string[]
     */
    private function remove_curly_braces( $name ) {
        if ( !$name ) {
            return $name;
        }
        return str_replace( array("{", "}"), "", $name );
    }

    public function get_src_set_url( $source_id, $source_size, $append_suffix = false ) : string {
        return $this->get_size_url( $source_id, $source_size, $append_suffix ) . " " . $source_size["width"] . "w";
    }

    public function get_size_url( $source_id, $source_size, $append_suffix = false ) {
        $width = $source_size["width"];
        $height = $source_size["height"];
        $url = wp_get_attachment_image_url( $source_id, array($width, $height) );
        $suffix = "-" . $width . "x" . $height;
        if ( $append_suffix && !str_contains( $url, $suffix ) ) {
            $explode = explode( ".", $url );
            $index = count( $explode ) - 2;
            $value = $explode[$index];
            $value = $value . $suffix;
            $explode[$index] = $value;
            $url = implode( ".", $explode );
        }
        return $url;
    }

    public function lpagery_containing_image_substitution_element( $keys, $name ) : bool {
        if ( !$name ) {
            return false;
        }
        $containing_substitution_element = sizeof( array_filter( $keys, function ( $element ) use($name) {
            $element = strtolower( $element );
            $lower_name = strtolower( $name );
            return str_contains( $lower_name, $element );
        } ) ) > 0;
        return $containing_substitution_element;
    }

    public function lpagery_make_image_url_relative( $url ) {
        $baseurl = wp_get_upload_dir()["baseurl"];
        return str_replace( $baseurl, "", $url );
    }

    /**
     * @param $source_post_id
     * @param array|object $source_attachments
     * @return array
     */
    public function get_translated_attachment( $source_post_id, $source_attachments ) {
        $source_attachment = null;
        $source_language_details = apply_filters( 'wpml_post_language_details', null, $source_post_id );
        if ( $source_language_details ) {
            $source_language_code = $source_language_details["language_code"];
            foreach ( $source_attachments as $one_source_attachment ) {
                $attachment_lang_details = apply_filters( 'wpml_post_language_details', null, $one_source_attachment->ID );
                if ( $attachment_lang_details["language_code"] == $source_language_code ) {
                    $source_attachment = (array) $one_source_attachment;
                    break;
                }
            }
            if ( !$source_attachment ) {
                $source_attachment = (array) $source_attachments[0];
            }
        }
        return $source_attachment;
    }

}
