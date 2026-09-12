<?php

namespace LPagery\service\live_render;

/**
 * Everything the render pipeline needs about one Live Mode Stub Post, resolved once per request:
 * the Template Page it renders from, its Row Data, and the source→target attachment ID pairs
 * (consumed by the render-time image maps in Phase 3).
 */
class LiveStubData
{
    /** The Template Page exists and is not in the trash (draft, pending, private, publish, ...). */
    public const TEMPLATE_STATE_PRESENT = 'present';
    /** The Template Page is in the trash, so this page is an Orphaned Live Page (ADR 0017). */
    public const TEMPLATE_STATE_TRASHED = 'trashed';
    /** The Template Page row is gone for good, so this page is an Orphaned Live Page (ADR 0017). */
    public const TEMPLATE_STATE_MISSING = 'missing';

    public int $template_id;
    /** @var array<string,mixed> */
    public array $row_data;
    /** @var array<string,mixed> */
    public array $attachment_pairs;
    /** Whether spintax was enabled for this page at generation (persisted in lpagery_settings). */
    public bool $spintax_enabled;
    /**
     * The page's persisted Spin Seed (ADR 0017), or null for a row that has none. Every live render
     * resolves spintax from it, so the page keeps the same variants across requests and across a
     * Classic/Live conversion.
     */
    public ?int $spin_seed;
    /**
     * State of the Template Page this stub renders from, resolved with the stub row: one of
     * `present`, `trashed`, `missing`. A page whose template is trashed or gone has nothing left to
     * render, so the render pipeline serves it as a 404 instead (ADR 0017).
     *
     * @var self::TEMPLATE_STATE_*
     */
    public string $template_state;

    /**
     * @param array<string,mixed>    $row_data
     * @param array<string,mixed>    $attachment_pairs
     * @param self::TEMPLATE_STATE_* $template_state
     */
    public function __construct(int $template_id, array $row_data, array $attachment_pairs = array(), bool $spintax_enabled = false, ?int $spin_seed = null, string $template_state = self::TEMPLATE_STATE_PRESENT)
    {
        $this->template_id = $template_id;
        $this->row_data = $row_data;
        $this->attachment_pairs = $attachment_pairs;
        $this->spintax_enabled = $spintax_enabled;
        $this->spin_seed = $spin_seed;
        $this->template_state = $template_state;
    }

    /**
     * An Orphaned Live Page: its Template Page is trashed or gone, so there is no design and no
     * content left to render from.
     */
    public function is_orphaned(): bool
    {
        return $this->template_state !== self::TEMPLATE_STATE_PRESENT;
    }
}
