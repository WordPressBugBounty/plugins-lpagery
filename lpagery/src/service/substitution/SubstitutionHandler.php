<?php

namespace LPagery\service\substitution;

use LPagery\service\save_page\additional\MpgSupportController__premium_only;
use LPagery\model\BaseParams;
use LPagery\model\Params;
use LPagery\utils\Utils;
use Throwable;
class SubstitutionHandler {
    private Spintax $spintax;

    private ImageSubstitutionHandler $imageSubstitutionHandler;

    public function __construct( Spintax $spintax, ImageSubstitutionHandler $imageSubstitutionHandler ) {
        $this->spintax = $spintax;
        $this->imageSubstitutionHandler = $imageSubstitutionHandler;
    }

    public function lpagery_substitute_slug( BaseParams $params, $content ) {
        return $this->substitute_sanitized_slug( $params, $content, [Utils::class, 'lpagery_sanitize_title_with_dashes'] );
    }

    /**
     * The slug a row produced before slug patterns transliterated diacritics: pattern and
     * Placeholder keys go through the legacy sanitizer. Used only to recognise a Generated Page
     * that was published under that spelling; never to build a new slug.
     */
    public function lpagery_substitute_legacy_slug( BaseParams $params, $content ) {
        return $this->substitute_sanitized_slug( $params, $content, [Utils::class, 'lpagery_sanitize_title_with_dashes_legacy'] );
    }

    /**
     * @param callable(string): string $sanitize the placeholder-preserving sanitizer applied to
     *                                            the pattern and to every Placeholder key
     */
    private function substitute_sanitized_slug( BaseParams $params, $content, callable $sanitize ) {
        $content = $sanitize( $content );
        $params_copy = new BaseParams();
        $sanitized_data = array();
        $sanitized_keys = array();
        foreach ( $params->keys as $index => $key ) {
            $value = $params->values[$index];
            $sanitized_key = $sanitize( $key );
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
                // Image processing is Extended-tier, but the gate sits inside handle_image_processing
                // so this call site survives Freemius' strip. Below Extended the helper answers the
                // content unchanged and the image params are empty, so the replacement is a no-op.
                $content = $this->handle_image_processing( $content, $params );
                if ( $params instanceof Params ) {
                    $content = self::lpagery_replace( $params->image_keys, $params->image_values, $content );
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
            // Check if key contains non-ASCII (e.g., umlauts)
            if ( preg_match( '/[^\\x00-\\x7F]/', $key_value ) ) {
                echo "Key contains non-ASCII characters: " . $key_value;
                // Unicode-aware, case-insensitive replace
                $pattern = '/' . preg_quote( $key_value, '/' ) . '/iu';
                $content = preg_replace( $pattern, $currentValue, $content );
            } else {
                // Fast path for ASCII
                $content = str_ireplace( $key_value, $currentValue, $content );
            }
            $content = str_ireplace( $key_value, $currentValue, $content );
        }
        return $content;
    }

    /**
     * No tier gate here: {@see Spintax::perform_spintax()} carries its own and answers the content
     * unchanged below Extended. Keeping the call site ungated is what lets it survive Freemius'
     * strip, which removes a `__premium_only` `if` whole.
     */
    private function handle_spintax( Params $params, string $content ) {
        $spintax_enabled = $params->spintax_enabled ?? false;
        if ( $spintax_enabled !== false ) {
            if ( strpos( $content, '|' ) !== false ) {
                $content = $this->spintax->perform_spintax( $content, $params->spin_seed );
            }
        }
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
        // Written out of non-suffixed calls: the free build has to keep this guard, not lose it with
        // the whole statement, because its call site in lpagery_substitute is ungated on purpose.
        // `is_premium()` is part of it — `is_plan_or_trial()` alone is true on a free build whose site
        // already carries a paid plan.
        if ( !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial( 'extended' )) ) {
            return $content;
        }
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
