<?php

namespace LPagery\service\live_render;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * O(1)-per-post-per-request gate for the render pipeline: "is this post a Live Mode stub, and if
 * so what are its Template Page ID and Row Data?" (ADR 0010).
 *
 * One indexed lookup per post is memoised in an in-request map — negatives included, so a non-stub
 * post costs at most one query per request. Backed by a direct DB read
 * ({@see GeneratedPageRepository::get_live_stub_data}) that bypasses the meta cache, so it is safe to
 * call from inside the `get_post_metadata` filter without re-entering it. A static in-progress flag
 * guards against re-entrancy while the lookup itself runs.
 */
class LiveStubResolver
{
    private GeneratedPageRepository $generatedPageRepository;
    /** @var array<int, ?LiveStubData> */
    private array $cache = array();
    private bool $resolving = false;
    /** Request-memoised "does any live stub exist at all?" — one cheap query site-wide. */
    private ?bool $anyLiveStubExists = null;

    public function __construct(GeneratedPageRepository $generatedPageRepository)
    {
        $this->generatedPageRepository = $generatedPageRepository;
    }


    public function resolve(int $post_id): ?LiveStubData
    {
        if ($post_id <= 0) {
            return null;
        }
        if (array_key_exists($post_id, $this->cache)) {
            return $this->cache[$post_id];
        }
        if ($this->resolving) {
            return null;
        }
        // Site-wide short-circuit: with zero live stubs the per-post lookup can never match, so one
        // cheap existence query per request keeps the meta proxy near-free when Live Mode is unused.
        if (!$this->live_stubs_exist()) {
            $this->cache[$post_id] = null;
            return null;
        }

        $this->resolving = true;
        try {
            $row = $this->generatedPageRepository->get_live_stub_data($post_id);
        } finally {
            $this->resolving = false;
        }

        if (empty($row)) {
            $this->cache[$post_id] = null;
            return null;
        }

        $row_data = maybe_unserialize($row->data);
        if (!is_array($row_data)) {
            $row_data = array();
        }

        $attachment_pairs = array();
        if (!empty($row->attachment_id_pairs)) {
            $unserialized_pairs = maybe_unserialize($row->attachment_id_pairs);
            if (is_array($unserialized_pairs)) {
                $attachment_pairs = $unserialized_pairs;
            }
        }

        // The page's generation-time settings snapshot; spintax only runs on live renders when the
        // user had it enabled for this set (Classic parity — absent/malformed reads as disabled).
        $spintax_enabled = false;
        if (!empty($row->lpagery_settings)) {
            $settings = maybe_unserialize($row->lpagery_settings);
            if (is_array($settings)) {
                $spintax_enabled = filter_var($settings['spintax_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // The page's persisted Spin Seed (ADR 0017): live renders resolve every spintax block from
        // it, so the wording stays put across requests and across a Classic/Live conversion.
        $spin_seed = isset($row->spin_seed) ? (int)$row->spin_seed : null;

        $stub = new LiveStubData((int)$row->template_id, $row_data, $attachment_pairs, $spintax_enabled, $spin_seed, $this->map_template_state($row));
        $this->cache[$post_id] = $stub;
        return $stub;
    }

    /**
     * The Template Page's state as the stub lookup's left join reports it: a missing row (null
     * status) or status `trash` makes this an Orphaned Live Page; every other status — draft,
     * pending, private, publish — is a template the render pipeline can still render from.
     *
     * A row without the column at all reads as present, so a partial row can never turn healthy
     * pages into 404s.
     *
     * @param object $row the stub row returned by {@see GeneratedPageRepository::get_live_stub_data}
     * @return LiveStubData::TEMPLATE_STATE_*
     */
    private function map_template_state($row): string
    {
        if (!property_exists($row, 'template_status')) {
            return LiveStubData::TEMPLATE_STATE_PRESENT;
        }
        $status = $row->template_status;
        if ($status === null || $status === '') {
            return LiveStubData::TEMPLATE_STATE_MISSING;
        }
        if ((string)$status === 'trash') {
            return LiveStubData::TEMPLATE_STATE_TRASHED;
        }
        return LiveStubData::TEMPLATE_STATE_PRESENT;
    }

    /**
     * The cheap site-wide gate: "does any live stub exist at all?" Memoised once per request here and
     * persisted across requests by the repository in an autoloaded option (issue #281), so a site that
     * never uses Live Mode spends no query on the gate at all. Every Render Mode write and every
     * Generated Page write bumps the token that answer is tagged with, so the request after the first
     * live stub is created re-queries and sees it. Public so the Image Endpoint can short-circuit a `/lpagery-img/` request to 404 before any
     * per-stub query when Live Mode is unused.
     */
    public function live_stubs_exist(): bool
    {
        if ($this->anyLiveStubExists === null) {
            $this->anyLiveStubExists = $this->generatedPageRepository->live_stubs_exist();
        }
        return $this->anyLiveStubExists;
    }
}
