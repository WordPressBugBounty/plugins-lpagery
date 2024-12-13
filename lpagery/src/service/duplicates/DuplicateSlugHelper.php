<?php

namespace LPagery\service\duplicates;

use LPagery\service\preparation\InputParamProvider;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\utils\Utils;
class DuplicateSlugHelper {
    private static $instance;

    private InputParamProvider $inputParamProvider;

    private SubstitutionHandler $substitutionHandler;

    public function __construct( InputParamProvider $inputParamProvider, SubstitutionHandler $substitutionHandler ) {
        $this->inputParamProvider = $inputParamProvider;
        $this->substitutionHandler = $substitutionHandler;
    }

    public static function get_instance( InputParamProvider $inputParamProvider, SubstitutionHandler $substitutionHandler ) {
        if ( null === self::$instance ) {
            self::$instance = new self($inputParamProvider, $substitutionHandler);
        }
        return self::$instance;
    }

    // Other methods of your class
    public function check_all_slugs_are_the_same( $slugs ) {
        if ( empty( $slugs ) || count( $slugs ) == 1 ) {
            return false;
        }
        $first_slug = $slugs[0];
        foreach ( $slugs as $slug ) {
            if ( $slug != $first_slug ) {
                return false;
            }
        }
        return true;
    }

    public function check_post_title_contains_at_least_one_placeholder( $title, $data ) {
        if ( empty( $data ) ) {
            return false;
        }
        $array_keys = array_keys( $data[0] );
        $placeholders = array_map( function ( $element ) {
            $returnValue = $element;
            if ( !str_starts_with( $returnValue, "{" ) ) {
                $returnValue = "{" . $returnValue;
            }
            if ( !str_ends_with( $returnValue, "}" ) ) {
                $returnValue = $returnValue . "}";
            }
            return strtolower( $returnValue );
            // Convert placeholders to lowercase
        }, $array_keys );
        $title = strtolower( $title );
        // Convert title to lowercase
        $placeholders = array_filter( $placeholders, function ( $element ) use($title) {
            return strpos( $title, $element ) !== false;
        } );
        return count( $placeholders ) > 0;
    }

    public function lpagery_find_array_duplicates( $arr ) {
        $duplicates = [];
        $indexes = [];
        foreach ( $arr as $index => $value ) {
            if ( !isset( $indexes[$value] ) ) {
                $indexes[$value] = [];
            }
            $indexes[$value][] = $index + 2;
        }
        foreach ( $indexes as $value => $indexArray ) {
            if ( count( $indexArray ) > 1 ) {
                $duplicates[] = [
                    'value' => $value,
                    'rows'  => $indexArray,
                ];
            }
        }
        return $duplicates;
    }

    public function lpagery_find_array_numeric_values( $arr ) {
        $numeric_values = [];
        foreach ( $arr as $index => $value ) {
            if ( is_numeric( $value ) ) {
                $numeric_values[] = [
                    'value' => $value,
                    'row'   => $index + 2,
                ];
            }
        }
        return $numeric_values;
    }

    public function get_filenames_slug_equals( $slug, $json_decode ) : array {
        return [];
    }

    public function get_slugs_from_json_input( $slug_from_dashboard, $json_decode ) : array {
        $slugs = array_map( function ( $element ) use($slug_from_dashboard) {
            $params = $this->inputParamProvider->lpagery_get_input_params_without_images( $element );
            $substituted_slug = $this->substitutionHandler->lpagery_substitute_slug( $params, $slug_from_dashboard );
            if ( array_key_exists( "lpagery_ignore", $element ) && filter_var( $element["lpagery_ignore"], FILTER_VALIDATE_BOOLEAN ) ) {
                return null;
            }
            return sanitize_title( $substituted_slug );
        }, $json_decode );
        // Use array_values to reindex the filtered array
        return array_values( array_filter( $slugs, function ( $element ) {
            return $element !== null;
            // Using strict comparison to ensure proper filtering
        } ) );
    }

}
