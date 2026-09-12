<?php

namespace LPagery\io;

/**
 * Capability level required by an {@see AjaxEndpoint}. A closed set of exactly three values —
 * the PHP 7.4 translation (ADR-0012) of the #183 "Cap enum NONE | EDITOR | ADMIN" decision:
 * a final class with a private constructor and three named factories instead of a PHP 8.1 enum.
 *
 * Build-universal: ships in every build and never references a __premium_only symbol. The premium
 * plan gate stays inline in the stripped premium handlers (ADR-0002), not here.
 */
final class Cap
{
    /** WordPress capability to require, or null for "nonce only" (NONE). */
    private $capability;

    private function __construct(?string $capability)
    {
        $this->capability = $capability;
    }

    /** Nonce only — preserves today's read handlers exactly. */
    public static function none(): self
    {
        return new self(null);
    }

    /** current_user_can('edit_pages'). */
    public static function editor(): self
    {
        return new self('edit_pages');
    }

    /** current_user_can('manage_options'). */
    public static function admin(): self
    {
        return new self('manage_options');
    }

    /**
     * Whether the current user satisfies this level. NONE is always satisfied (the nonce is checked
     * separately by the endpoint); EDITOR/ADMIN defer to current_user_can.
     */
    public function is_satisfied(): bool
    {
        return $this->capability === null || current_user_can($this->capability);
    }
}
