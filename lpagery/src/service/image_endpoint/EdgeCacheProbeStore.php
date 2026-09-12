<?php

namespace LPagery\service\image_endpoint;

/**
 * The persisted **Edge Cache Probe** state (CONTEXT.md glossary, issue #233): three ordinary
 * autoload=false options that together record an in-flight probe run and its outcome. Sibling of
 * {@see EndpointHealthStore} — out-of-band state consulted only off the request's critical path, so it
 * must never ride on the per-request autoload.
 *
 * - **token** ({@see self::OPTION_TOKEN}): the random token minted for a live run and cleared when the
 *   run ends. It exists only while a probe is in flight; the {@see ImageEndpoint} serves the probe image
 *   only for a request whose token matches the stored one. The token is persisted **with an expiry**
 *   ({@see self::TOKEN_TTL}) and reads as absent once expired — so a run that dies before its clear path
 *   (process kill, fatal) never leaves a standing unauthenticated URL behind. The token row also carries
 *   the run's **origin-hit counter**: {@see ImageEndpoint} calls {@see self::record_hit()} for every
 *   token-matched request that actually reached PHP, and {@see EdgeCacheProbe} reads it back through
 *   {@see self::get_hits()} to classify — a fetch the origin never saw was absorbed by a cache upstream.
 * - **attempts** ({@see self::OPTION_ATTEMPTS}): how many inconclusive runs have happened, for the cap.
 * - **verdict** ({@see self::OPTION_VERDICT}): the provenance record — `absorbed` (an Edge Cache was
 *   detected) or `unabsorbed` (none). Absent means undetermined; the run keeps retrying until conclusive.
 *
 * Written by {@see EdgeCacheProbe}; the token is read (and its hit counter bumped) by {@see ImageEndpoint}.
 */
class EdgeCacheProbeStore
{
    public const OPTION_TOKEN = 'lpagery_edge_cache_probe_token';
    public const OPTION_ATTEMPTS = 'lpagery_edge_cache_probe_attempts';
    public const OPTION_VERDICT = 'lpagery_edge_cache_probe_verdict';

    /** An Edge Cache absorbed at least one probe fetch (cache-hit evidence) — Virtual Image URLs default on. */
    public const VERDICT_ABSORBED = 'absorbed';
    /** Every probe fetch came straight from the origin with no hit evidence — default stays off. */
    public const VERDICT_UNABSORBED = 'unabsorbed';

    /**
     * How long a persisted token stays valid. A run's fetches finish in seconds; the TTL only bounds
     * the damage of a run that never reached its clear path — an expired token reads as absent.
     */
    public const TOKEN_TTL = 5 * MINUTE_IN_SECONDS;

    /** The token of the run currently in flight, or null when no run is live (or the token expired). */
    public function get_token(): ?string
    {
        $stored = get_option(self::OPTION_TOKEN, array());
        if (!is_array($stored) || !isset($stored['token'], $stored['expires'])) {
            return null;
        }
        if (!is_string($stored['token']) || $stored['token'] === '' || (int)$stored['expires'] < time()) {
            return null;
        }
        return $stored['token'];
    }

    public function set_token(string $token): void
    {
        update_option(
            self::OPTION_TOKEN,
            array('token' => $token, 'expires' => time() + self::TOKEN_TTL, 'hits' => 0),
            false
        );
    }

    /**
     * End a run. When `$token` is given, the row is deleted only while it still belongs to that run — a
     * later cycle that has already minted its own token must not lose it to an earlier cycle's cleanup.
     */
    public function clear_token(?string $token = null): void
    {
        if ($token !== null) {
            $stored = get_option(self::OPTION_TOKEN, array());
            if (is_array($stored) && isset($stored['token']) && $stored['token'] !== $token) {
                return;
            }
        }
        delete_option(self::OPTION_TOKEN);
    }

    /**
     * Count one probe request that actually reached the origin. Called by the {@see ImageEndpoint} for
     * every token-matched probe request (200 and 304 alike) — this is the classification signal: a fetch
     * that never incremented the counter was absorbed by a cache in front of the origin. A no-op when no
     * run is in flight (malformed/absent row), so a stray late request can never corrupt other state.
     */
    public function record_hit(): void
    {
        $stored = get_option(self::OPTION_TOKEN, array());
        if (!is_array($stored) || !isset($stored['token'], $stored['expires'])) {
            return;
        }
        $stored['hits'] = (int)($stored['hits'] ?? 0) + 1;
        update_option(self::OPTION_TOKEN, $stored, false);
    }

    /**
     * The in-flight run's origin-hit count. The increments happen in the endpoint's separate PHP
     * processes while the probing process still holds the row it wrote in its own options cache — so the
     * cached copy is dropped first and the count re-read fresh (works with and without a persistent
     * object cache).
     */
    public function get_hits(): int
    {
        wp_cache_delete(self::OPTION_TOKEN, 'options');
        $stored = get_option(self::OPTION_TOKEN, array());
        return (is_array($stored) && isset($stored['hits'])) ? (int)$stored['hits'] : 0;
    }

    public function get_attempts(): int
    {
        return (int)get_option(self::OPTION_ATTEMPTS, 0);
    }

    public function set_attempts(int $attempts): void
    {
        update_option(self::OPTION_ATTEMPTS, $attempts, false);
    }

    public function clear_attempts(): void
    {
        delete_option(self::OPTION_ATTEMPTS);
    }

    /** The provenance verdict, or null while it is still undetermined. */
    public function get_verdict(): ?string
    {
        $verdict = get_option(self::OPTION_VERDICT, '');
        return (is_string($verdict) && $verdict !== '') ? $verdict : null;
    }

    public function set_verdict(string $verdict): void
    {
        update_option(self::OPTION_VERDICT, $verdict, false);
    }

    public function clear_verdict(): void
    {
        delete_option(self::OPTION_VERDICT);
    }
}
