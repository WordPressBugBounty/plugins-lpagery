<?php

namespace LPagery\service\image_endpoint;

use LPagery\service\live_render\LivePagePurger;
use LPagery\service\settings\SettingsController;

/**
 * The **Edge Cache Probe** orchestrator (issue #233): decide the Virtual Image URLs resting default from
 * evidence rather than guesswork. It mints a one-off token, serves a tiny image at the reserved probe URL
 * ({@see VirtualImageUrl::probe_path()}, {@see ImageEndpoint}), and fetches that URL **several times
 * through the public front door** ({@see EdgeCacheProbeTransport}) — a seed fetch plus spaced follow-ups
 * ({@see self::FETCH_COUNT}/{@see self::FETCH_SPACING_SECONDS}), because real edge caches write their
 * cache asynchronously and load-balance across nodes, so absorption may only show on a later fetch.
 *
 * Classification is **origin-side hit counting**, not header sniffing: the {@see ImageEndpoint} counts
 * every token-matched probe request that actually reaches PHP ({@see EdgeCacheProbeStore::record_hit()}).
 * A hit per fetch means nothing cached in between (unabsorbed); fewer hits than fetches mean an Edge
 * Cache answered at least one of them (absorbed). This is vendor-agnostic by construction — cache-status
 * response headers are an unbounded, vendor-specific namespace (`cf-cache-status`, `x-hcdn-cache-status`,
 * …) and a cache that emits none would silently misclassify — whereas "did the request reach us?" is
 * ground truth the origin owns.
 *
 * Sibling of {@see EndpointHealthProbe}: injected HTTP transport, pure classification kept apart from the
 * side effects, a persisted {@see EdgeCacheProbeStore}, and the shared {@see LivePagePurger} seam so a
 * decision that changes the site-wide render shape converges cached HTML immediately.
 *
 * **Compute-once**: the scheduled probe runs only while the setting option is unset and only up to
 * {@see self::MAX_ATTEMPTS} inconclusive attempts. The first conclusive verdict, an explicit settings
 * save, or the cap ends it permanently — the persisted option makes every later scheduled run a no-op.
 * {@see self::run_manual()} (the settings-screen "run detection" action) bypasses those dormancy gates to
 * refresh the provenance verdict on demand, but never overwrites an already-decided setting value.
 * All FREE code (ADR 0009/0013).
 */
class EdgeCacheProbe
{
    /** The site-wide Virtual Image URLs option (ADR 0015/0016) — the same one a manual save writes. */
    private const SETTING_OPTION = SettingsController::OPTION_VIRTUAL_IMAGES_ENABLED;

    /** Total inconclusive scheduled attempts before the probe gives up and stays dormant. */
    public const MAX_ATTEMPTS = 5;

    /**
     * How many times one cycle fetches the probe URL: a seed fetch plus follow-ups. More than two because
     * real edge caches (e.g. Hostinger's) write their cache **asynchronously** and load-balance across
     * edge nodes — a second immediate fetch can reach the origin even though an absorbing cache is there.
     */
    private const FETCH_COUNT = 3;

    /**
     * Pause between consecutive fetches, giving an asynchronous edge-cache write time to land before the
     * next fetch asks for the entry. Runs in cron/AJAX context only — never on a visitor request.
     */
    private const FETCH_SPACING_SECONDS = 2;

    private EdgeCacheProbeTransport $transport;
    private EdgeCacheProbeStore $store;
    private LivePagePurger $purger;

    public function __construct(
        EdgeCacheProbeTransport $transport,
        EdgeCacheProbeStore $store,
        LivePagePurger $purger
    ) {
        $this->transport = $transport;
        $this->store = $store;
        $this->purger = $purger;
    }

    /**
     * Run one scheduled probe cycle and report the terminal branch for the scheduler
     * ({@see EdgeCacheProbeResult}). A no-op (dormant) when the setting is already decided or the attempt
     * cap is spent; otherwise it fetches the tokenized probe URL {@see self::FETCH_COUNT} times, classifies, and either records a
     * conclusive verdict or counts an inconclusive attempt.
     */
    public function run(): int
    {
        // Compute-once: read the RAW option (never the isVirtualImagesEnabled() default-collapse) so an
        // explicit '0' or '1' — set by a conclusive run or a manual save — permanently stops probing.
        if (get_option(self::SETTING_OPTION, false) !== false) {
            return EdgeCacheProbeResult::DORMANT;
        }
        if ($this->store->get_attempts() >= self::MAX_ATTEMPTS) {
            return EdgeCacheProbeResult::DORMANT;
        }

        $verdict = $this->run_probe_cycle();
        if ($verdict === null) {
            $attempts = $this->store->get_attempts() + 1;
            $this->store->set_attempts($attempts);
            // The attempt that spends the cap is terminal — report dormant so the scheduler stops retrying.
            return $attempts >= self::MAX_ATTEMPTS ? EdgeCacheProbeResult::DORMANT : EdgeCacheProbeResult::RETRY;
        }

        // A manual save can land while the fetches were in flight; losing the claim means the operator
        // decided, which ends probing permanently — dormant, not conclusive.
        return $this->apply_verdict($verdict) ? EdgeCacheProbeResult::CONCLUSIVE : EdgeCacheProbeResult::DORMANT;
    }

    /**
     * The settings-screen "run detection" action (on-demand re-probe): run one cycle **bypassing the
     * dormancy gates** — the operator asked, so an already-set option or a spent attempt cap must not
     * stop the measurement. A conclusive verdict refreshes the provenance record and resets the attempt
     * counter; the setting value itself is only claimed when still unset (same atomic `add_option` rule
     * as the scheduled path), so a re-run never flips a value the operator — or an earlier run — decided.
     *
     * @return string|null The fresh verdict, or null when this cycle was inconclusive (nothing changes).
     */
    public function run_manual(): ?string
    {
        $verdict = $this->run_probe_cycle();
        if ($verdict === null) {
            return null;
        }
        $this->store->set_verdict($verdict);
        $this->store->clear_attempts();

        $newEffective = ($verdict === EdgeCacheProbeStore::VERDICT_ABSORBED);
        if (add_option(self::SETTING_OPTION, $newEffective ? '1' : '0') && $newEffective) {
            // The claim succeeded, so the option was unset (effectively off) — absorbed flips the
            // site-wide render shape on, which must purge cached live HTML just like the scheduled path.
            $this->purger->purge_all_live_pages();
        }
        return $verdict;
    }

    /**
     * Issue a fresh token, fetch the probe URL {@see self::FETCH_COUNT} times through the public front
     * door ({@see self::FETCH_SPACING_SECONDS} apart), and classify from the origin-hit counter — always
     * clearing the token afterwards (including on a transport failure path, via `finally`). Returns the
     * verdict, or null when the cycle was inconclusive.
     *
     * Cycles are **serialized on the token row**: a scheduled run and a manual run can overlap (cron
     * fires while the operator clicks "run detection"), and both share one token + hit counter. While a
     * live, unexpired token exists this cycle does not start and reports inconclusive — otherwise the
     * second cycle would replace the first's token mid-flight (its remaining fetches 404) and the
     * first's cleanup would delete the second's token. {@see EdgeCacheProbeStore::TOKEN_TTL} bounds how
     * long a run that died before its cleanup can hold the lease.
     */
    private function run_probe_cycle(): ?string
    {
        if ($this->store->get_token() !== null) {
            return null;
        }
        $token = wp_generate_password(32, false);
        $this->store->set_token($token);
        try {
            $url = home_url(VirtualImageUrl::probe_path($token));
            $results = array();
            for ($i = 0; $i < self::FETCH_COUNT; $i++) {
                if ($i > 0) {
                    $this->pause(self::FETCH_SPACING_SECONDS);
                }
                $results[] = $this->transport->fetch($url);
            }
            $origin_hits = $this->store->get_hits();
        } finally {
            $this->store->clear_token($token);
        }
        return $this->classify($results, $origin_hits);
    }

    /** Seam for the inter-fetch pause so tests do not sleep. */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Persist a conclusive verdict by **atomically claiming the unset option slot** with `add_option()`,
     * which fails when the option already exists — so a manual save that landed while the fetches were in
     * flight always wins and the probe writes nothing (no verdict, no purge). On a successful claim the
     * provenance verdict is recorded and live pages purge **iff the effective value changed** — the option
     * was unset before the claim (compute-once), so its previous effective value is off (unset ≡ off);
     * absorbed flips it on and purges, unabsorbed leaves it off and purges nothing. The direction is never
     * hardcoded: it is the `$newEffective !== $previousEffective` comparison that decides.
     *
     * @return bool Whether the claim succeeded and the verdict was persisted.
     */
    private function apply_verdict(string $verdict): bool
    {
        $previousEffective = false; // the option is unset here (compute-once gate), so effectively off.
        $newEffective = ($verdict === EdgeCacheProbeStore::VERDICT_ABSORBED);

        if (!add_option(self::SETTING_OPTION, $newEffective ? '1' : '0')) {
            return false;
        }
        $this->store->set_verdict($verdict);

        if ($newEffective !== $previousEffective) {
            $this->purger->purge_all_live_pages();
        }
        return true;
    }

    /**
     * Pure classification from the transport results and the origin-hit count. Every fetch must return
     * the probe image cleanly (200 + `image/*`); then the counter decides — fewer origin hits than clean
     * fetches means at least one fetch was answered without reaching the origin (absorbed), a hit per
     * fetch means nothing cached in between (unabsorbed). "Any fetch absorbed" rather than "the second
     * fetch absorbed" is deliberate: real edge caches write asynchronously and load-balance across nodes,
     * so absorption may only show on a later, spaced fetch. Any other count is inconclusive: zero hits
     * can't happen for a never-before-seen URL (something replayed or rewrote the requests), and more
     * hits than fetches means foreign traffic reached the token URL — neither is evidence either way.
     *
     * @param array<int,array{ok:bool, code:int, content_type:string}> $results one entry per fetch, in order
     * @return string|null {@see EdgeCacheProbeStore::VERDICT_ABSORBED} / `VERDICT_UNABSORBED`, or null when inconclusive.
     */
    public function classify(array $results, int $origin_hits): ?string
    {
        if ($results === array()) {
            return null;
        }
        foreach ($results as $result) {
            if (!$this->is_clean_image($result)) {
                return null;
            }
        }
        if ($origin_hits >= 1 && $origin_hits < count($results)) {
            return EdgeCacheProbeStore::VERDICT_ABSORBED;
        }
        if ($origin_hits === count($results)) {
            return EdgeCacheProbeStore::VERDICT_UNABSORBED;
        }
        return null;
    }

    /** @param array{ok:bool, code:int, content_type:string} $result */
    private function is_clean_image(array $result): bool
    {
        return $result['ok'] === true
            && $result['code'] === 200
            && stripos($result['content_type'], 'image/') === 0;
    }
}
