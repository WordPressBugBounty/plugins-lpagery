<?php

namespace LPagery\service\taxonomies;

use LPagery\service\substitution\SubstitutionHandler;
use LPagery\model\BaseParams;
use LPagery\model\Params;
class TaxonomySaveHandler {
    private static $instance;

    private $substitutionHandler;

    public function __construct( SubstitutionHandler $substitutionHandler ) {
        $this->substitutionHandler = $substitutionHandler;
    }

    public static function get_instance( SubstitutionHandler $substitutionHandler ) {
        if ( null === self::$instance ) {
            self::$instance = new self($substitutionHandler);
        }
        return self::$instance;
    }

    public function lpagery_set_taxonomies( Params $params, $post_id ) {
    }

    private function lpagery_get_taxonomies(
        Params $params,
        $json_data,
        $taxonomy_header_name,
        $taxonomies_from_dashboard = []
    ) {
        return array();
    }

    private function is_valid_taxonomy_header( $json_data, $taxonomy_header_name ) {
        return array_key_exists( $taxonomy_header_name, $json_data ) && !empty( $json_data[$taxonomy_header_name] ) && $json_data[$taxonomy_header_name] !== 'null';
    }

    private function get_tax_name_by_field_name( $field_name ) {
        switch ( $field_name ) {
            case 'lpagery_categories':
                return 'category';
            case 'lpagery_tags':
                return 'post_tag';
            default:
                $parts = explode( '_', $field_name );
                if ( count( $parts ) < 3 ) {
                    return 'not found taxonomy';
                }
                // Join the array parts starting from the third element (index 2)
                return implode( '_', array_slice( $parts, 2 ) );
        }
    }

    private function get_or_create_taxonomy( $tax_value, Params $params, $taxonomy ) {
        return null;
    }

    /**
     * @param $params
     * @param $cat
     * @return false|mixed|object|string
     */
    private function lpagery_substitute( BaseParams $params, $cat ) {
        $lpagery_substitute = $this->substitutionHandler->lpagery_substitute( $params, $cat );
        return $lpagery_substitute;
    }

    private function get_or_create_hierarchical_tax( $name, Params $params, $taxonomy ) {
        return null;
    }

    private function get_tax_ID( $tax_name, $tax_value ) {
        $cat = get_term_by( 'name', $tax_value, $tax_name );
        return ( $cat ? $cat->term_id : 0 );
    }

    private function create_hierarchical_tax( $category_names, $taxonomy_name ) {
        return null;
    }

    private function is_wp_error( $result ) {
        return is_wp_error( $result );
    }

    private function create_or_get_taxonomy_id( $tax_name, $tax_value ) {
        return null;
    }

}
