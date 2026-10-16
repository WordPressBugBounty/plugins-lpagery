<?php

namespace LPagery\suite;

use WP_Error;

/**
 * Pure (WordPress-independent) decryption and validation of an app bearer token.
 *
 * Mirrors the free WP Connector's scheme byte-for-byte so a bearer minted by
 * either plugin validates on the other. The bearer wire format is
 * `iv_b64:enc_b64`, where the ciphertext is the AES-256-CBC encryption of
 * "$token|$nonce". The encryption key is derived from a shared secret via
 * sha256. The secret is passed in so the caller decides which secret to use
 * (per-token row secret or the legacy Freemius fallback).
 */
class BearerTokenDecryptor
{
    /**
     * Decrypt the bearer with the given secret, validate nonce freshness, and
     * return the sha256 hash of the embedded token for DB lookup.
     *
     * @param string $bearer The bearer string (`iv_b64:enc_b64`).
     * @param string $secret Shared secret the bearer was encrypted with.
     * @param int    $now    Current UNIX timestamp (injected for testability).
     * @return array{token_hash: string}|WP_Error
     */
    public function decrypt_and_validate(string $bearer, string $secret, int $now)
    {
        if (strpos($bearer, ':') === false) {
            return new WP_Error('invalid_token_format', 'Invalid token format', array('status' => 401));
        }

        list($iv_b64, $encrypted_b64) = explode(':', $bearer, 2);
        $iv = base64_decode($iv_b64);
        $encrypted_data = base64_decode($encrypted_b64);

        // A short/garbage IV must be rejected up front: some OpenSSL builds silently
        // null-pad a wrong-length IV, which would make a crafted short IV predictable.
        if (strlen($iv) !== 16) {
            return new WP_Error('invalid_token_format', 'Invalid token format', array('status' => 401));
        }

        $key = hash('sha256', $secret, true);
        $decrypted = openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            return new WP_Error('decryption_failed', 'decryption_failed', array('status' => 401));
        }

        $parts = explode('|', $decrypted);
        if (count($parts) !== 2) {
            return new WP_Error('invalid_token_format', 'Invalid token format', array('status' => 401));
        }

        list($token, $nonce) = $parts;

        // Validate nonce freshness (max 1 hour). Nonce is "Y-m-d\TH" (UTC, hour granularity).
        $nonce_time = strtotime($nonce . ':00:00Z');
        if (false === $nonce_time || abs($now - $nonce_time) > 3600) {
            return new WP_Error('token_expired', 'Token has expired', array('status' => 401));
        }

        return array('token_hash' => hash('sha256', $token));
    }
}
