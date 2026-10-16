<?php

namespace LPagery\io;

/**
 * Capability level required by an {@see AjaxEndpoint}. A closed set of two values — the PHP 7.4
 * translation (ADR-0012) of the #183 Cap enum decision: a final class with a private constructor and
 * named factories instead of a PHP 8.1 enum. There is deliberately no nonce-only level: the
 * lpagery_ajax nonce reaches users below the plugin's capability (e.g. via list-table row actions),
 * so every endpoint must also check a capability.
 *
 * Build-universal: ships in every build and never references a __premium_only symbol. The premium
 * plan gate stays inline in the stripped premium handlers (ADR-0002), not here.
 */
final class Cap
{
    /** WordPress capability to require. */
    private $capability;

    private function __construct(string $capability)
    {
        $this->capability = $capability;
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
     * Whether the current user satisfies this level (the nonce is checked separately by the endpoint).
     */
    public function is_satisfied(): bool
    {
        return current_user_can($this->capability);
    }
}
