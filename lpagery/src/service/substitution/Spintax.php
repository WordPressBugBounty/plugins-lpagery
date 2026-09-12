<?php

namespace LPagery\service\substitution;

class Spintax {
    public function __construct() {
    }

    /**
     * Resolves every spintax block in the content.
     *
     * With a Spin Seed the resolution is deterministic and order-independent: each block's
     * pick depends only on the seed and on the block's own inner text (ADR 0017). Without a
     * seed the pick is random, as it has always been. The global random generator is never
     * seeded and never consulted when a seed is supplied.
     *
     * @param int|null $seed The page's Spin Seed, or null for a random pick.
     * @return string|null
     */
    public function perform_spintax( string $content, ?int $seed = null ) {
        return $content;
    }

    /**
     * @param array<int,string> $text
     */
    private function lpagery_replace( array $text, ?int $seed ) : string {
        $processed = $this->lpagery_process( $text[1], $seed );
        if ( strpos( $processed, '|' ) === false || strpos( $processed, '||' ) !== false ) {
            return '{' . $processed . '}';
        }
        $parts = explode( '|', $processed );
        if ( $seed === null ) {
            return $parts[array_rand( $parts )];
        }
        return $parts[$this->pick_index( $seed, $processed, count( $parts ) )];
    }

    private function lpagery_process( string $text, ?int $seed ) : string {
        $processed = preg_replace_callback( '/\\{((?>[^\\{\\}]+|(?R))*?)\\}/x', function ( $matches ) use($seed) {
            return $this->lpagery_replace( $matches, $seed );
        }, $text );
        return $processed ?? $text;
    }

    /**
     * The frozen pick rule (ADR 0017): CRC-32 of the seed's decimal string concatenated with
     * the block's inner text (pipes included, nested blocks already resolved), normalised to
     * an unsigned 32-bit value, modulo the number of options.
     *
     * The modulo runs through fmod on the unsigned decimal string so the result is identical
     * on 32-bit and 64-bit PHP; every value involved is well below the exact-integer range of
     * a double.
     */
    private function pick_index( int $seed, string $block_inner_text, int $option_count ) : int {
        $hash = (float) sprintf( '%u', crc32( (string) $seed . $block_inner_text ) );
        return (int) fmod( $hash, (float) $option_count );
    }

}
