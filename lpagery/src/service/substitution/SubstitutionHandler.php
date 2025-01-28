<?php

namespace LPagery\service\substitution;

use LPagery\service\save_page\additional\MpgSupportController__premium_only;
use LPagery\model\BaseParams;
use LPagery\model\Params;
use LPagery\utils\Utils;
use Throwable;
class SubstitutionHandler {
    private static ?SubstitutionHandler $instance = null;

    private Spintax $spintax;

    private ImageSubstitutionHandler $imageSubstitutionHandler;

    public function __construct( Spintax $spintax, ImageSubstitutionHandler $imageSubstitutionHandler ) {
        $this->spintax = $spintax;
        $this->imageSubstitutionHandler = $imageSubstitutionHandler;
    }

    public static function get_instance( Spintax $spintax, ImageSubstitutionHandler $imageSubstitutionHandler ) {
        if ( null === self::$instance ) {
            self::$instance = new self($spintax, $imageSubstitutionHandler);
        }
        return self::$instance;
    }

    public function lpagery_substitute_slug( BaseParams $params, $content ) {
        $content = Utils::lpagery_sanitize_title_with_dashes( $content );
        $params_copy = new BaseParams();
        $sanitized_data = array();
        $sanitized_keys = array();
        foreach ( $params->keys as $index => $key ) {
            $value = $params->values[$index];
            $sanitized_key = Utils::lpagery_sanitize_title_with_dashes( $key );
            $sanitized_data[$sanitized_key] = $value;
            $sanitized_keys[] = $sanitized_key;
        }
        $params_copy->raw_data = $sanitized_data;
        $params_copy->keys = $sanitized_keys;
        $params_copy->values = $params->values;
        return $this->lpagery_substitute( $params_copy, $content );
    }

    public function lpagery_substitute( BaseParams $params, $content ) {
        $json = false;
        if ( $this->is_json( $content ) ) {
            $json = true;
            $content = json_decode( $content, true );
        }
        if ( is_object( $content ) ) {
            $content = (object) $this->lpagery_substituteArray( $params, (array) $content );
        } elseif ( is_array( $content ) ) {
            $content = $this->lpagery_substituteArray( $params, (array) $content );
        } else {
            if ( is_string( $content ) ) {
                $keys = $params->keys;
                $values = $params->values;
                $content = $this->lpagery_replace( $keys, $values, $content );
                if ( $params instanceof Params ) {
                    $content = $this->handle_spintax( $params, $content );
                }
                if ( self::is_HTML( $content ) ) {
                    foreach ( $keys as $index => $key ) {
                        $replaced_key = str_replace( array("{", "}"), "", $key );
                        if ( empty( $replaced_key ) ) {
                            continue;
                        }
                        if ( str_contains( $content, $replaced_key ) ) {
                            $pattern = "/{(<[^<]*?>)" . $replaced_key . "<.*?>}/";
                            $replacement = $values[$index];
                            if ( $replacement == null ) {
                                $replacement = "";
                            }
                            try {
                                $replaced_content = preg_replace( $pattern, $replacement, $content );
                                if ( $replaced_content ) {
                                    $content = $replaced_content;
                                }
                            } catch ( \Throwable $e ) {
                                error_log( "Error in preg_replace: " . $e->getMessage() . " " . $e->getTraceAsString() );
                            }
                        }
                    }
                }
                $content = self::escape_css_vars( $content );
            }
            $content = self::replace_numeric_values( $content, $params );
        }
        $return_value = $content;
        if ( $json ) {
            $return_value = json_encode( $content, JSON_UNESCAPED_SLASHES );
        }
        return $return_value;
    }

    private function is_json( $content ) : bool {
        return is_string( $content ) && is_array( json_decode( $content, true ) );
    }

    public function lpagery_substituteArray( BaseParams $params, $array ) {
        array_walk_recursive( $array, function ( &$value ) use($params) {
            $value = self::lpagery_substitute( $params, $value );
        } );
        return $array;
    }

    /**
     * @param $keys
     * @param $values
     * @param $content
     * @return string
     */
    private function lpagery_replace( $keys, $values, $content ) {
        foreach ( $keys as $index => $key_value ) {
            $currentValue = $values[$index];
            if ( is_null( $currentValue ) ) {
                $currentValue = "";
            }
            $content = str_ireplace( $key_value, $currentValue, $content );
        }
        return $content;
    }

    private function handle_spintax( Params $params, string $content ) {
        return $content;
    }

    private function is_HTML( $string ) {
        if ( $string != strip_tags( $string ) ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param mixed $content
     * @return array|mixed|string|string[]
     */
    private function escape_css_vars( $content ) {
        if ( str_contains( $content, "var(\\u002d\\u002d" ) ) {
            $content = str_replace( "var(\\u002d\\u002d", "var(\\\\u002d\\\\u002d", $content );
        }
        return $content;
    }

    /**
     * @param $content
     * @param BaseParams $params
     * @return array|false|mixed|string|string[]
     */
    private function handle_image_processing( $content, BaseParams $params ) {
        if ( $params instanceof Params ) {
            if ( str_contains( $content, "<img" ) && $params->image_processing_enabled && $this->is_HTML( $content ) ) {
                $content = $this->imageSubstitutionHandler->replace_images_from_html( $content, $params );
            }
        }
        return $content;
    }

    /**
     * @param $content
     * @param BaseParams $params
     * @return mixed
     */
    private function replace_numeric_values( $content, BaseParams $params ) {
        $source_attachment_ids = array();
        $target_attachment_ids = array();
        if ( $params instanceof Params ) {
            $source_attachment_ids = $params->source_attachment_ids;
            $target_attachment_ids = $params->target_attachment_ids;
        }
        $numeric_keys = $params->numeric_keys ?? array();
        $numeric_values = $params->numeric_values ?? array();
        if ( is_numeric( $content ) ) {
            foreach ( $source_attachment_ids as $key => $source_attachment_id ) {
                $target_attachment_id = $target_attachment_ids[$key];
                if ( $content == $source_attachment_id ) {
                    $content = $target_attachment_id;
                }
            }
            foreach ( $numeric_keys as $key => $numeric_key ) {
                $numeric_value = $numeric_values[$key];
                if ( $content == $numeric_key ) {
                    $content = $numeric_value;
                }
            }
        }
        return $content;
    }

}
