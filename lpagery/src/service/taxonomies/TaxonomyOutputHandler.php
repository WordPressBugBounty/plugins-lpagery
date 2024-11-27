<?php

namespace LPagery\service\taxonomies;

use LPagery\service\substitution\SubstitutionHandler;
use LPagery\model\BaseParams;
use LPagery\model\Params;
class TaxonomyOutputHandler {
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

    public function lpagery_get_taxonomy_label( $name ) {
        return $name;
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

    private function lpagery_substitute( BaseParams $params, $cat ) {
        $lpagery_substitute = $this->substitutionHandler->lpagery_substitute( $params, $cat );
        return $lpagery_substitute;
    }

    private function get_tax_ID( $tax_name, $tax_value ) {
        $cat = get_term_by( 'name', $tax_value, $tax_name );
        return ( $cat ? $cat->term_id : 0 );
    }

    public function lpagery_generate_taxonomy_output( BaseParams $params, $json_data, $fieldname ) {
        return null;
    }

    private function generate_taxonomy_output( BaseParams $params, $taxonomies, $found_taxonomy ) {
        return null;
    }

    private function process_hierarchical_taxonomy( BaseParams $params, $category_group, $found_taxonomy ) {
        return null;
    }

    private function process_non_hierarchical_taxonomy( BaseParams $params, $tax_value, $found_taxonomy ) {
        return null;
    }

}
