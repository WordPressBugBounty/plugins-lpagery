<?php

namespace LPagery\io;

use Throwable;

/**
 * Single dispatcher every JSON-returning AJAX action registers through (#183). It owns the fixed
 * envelope that used to be re-typed per handler: nonce verification, the capability check, the
 * try/catch, and the one uniform JSON error contract. A handler shrinks to its real content —
 * read params, call a controller, return the payload to be JSON-encoded.
 *
 * Build-universal: lives in always-included code and never references a __premium_only symbol.
 * Premium handlers keep their inline lpagery_require_views_pro() gate inside the stripped premium
 * file (ADR-0002).
 */
final class AjaxEndpoint
{
    /**
     * Register $handler for wp_ajax_$action behind the shared envelope.
     *
     * @param callable $handler returns the payload wp_send_json()'d on success
     */
    public static function register(string $action, Cap $cap, callable $handler): void
    {
        add_action('wp_ajax_' . $action, static function () use ($cap, $handler): void {
            self::dispatch($cap, $handler);
        });
    }

    /**
     * Verify the nonce, enforce the capability, run the handler, and emit the JSON response.
     * Any Throwable becomes the uniform {success:false, exception:<message>} contract.
     */
    public static function dispatch(Cap $cap, callable $handler): void
    {
        check_ajax_referer('lpagery_ajax');
        if ($cap->is_satisfied()) {
            try {
                wp_send_json($handler());
            } catch (Throwable $exception) {
                wp_send_json(array("success" => false, "exception" => $exception->getMessage()));
            }
        } else {
            wp_send_json(array("success" => false, "exception" => 'You do not have permission to perform this action.'));
        }
    }
}
