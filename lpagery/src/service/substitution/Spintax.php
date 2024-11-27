<?php

namespace LPagery\service\substitution;

class Spintax {
    private static ?Spintax $instance = null;

    private function __construct() {
    }

    public static function get_instance() : Spintax {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function perform_spintax( string $content ) {
        return $content;
    }

    private function lpagery_replace( $text ) {
        $processed = $this->lpagery_process( $text[1] );
        if ( strpos( $processed, '|' ) === false || strpos( $processed, '||' ) !== false ) {
            return '{' . $processed . '}';
        }
        $parts = explode( '|', $processed );
        return $parts[array_rand( $parts )];
    }

    private function lpagery_process( $text ) {
        return preg_replace_callback( '/\\{((?>[^\\{\\}]+|(?R))*?)\\}/x', array($this, 'lpagery_replace'), $text );
    }

}
