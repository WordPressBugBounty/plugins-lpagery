<?php

namespace LPagery\service\live_render;

/**
 * Answers "is this Template Page's page builder supported by Live Mode?" (ADR 0007, "Builders v1").
 *
 * v1 renders live only for Elementor and Gutenberg/classic — the two builders whose CSS the render
 * pipeline can inline and substitute. Every other known builder (Divi, WPBakery, Bricks, Breakdance,
 * Oxygen, Brizy, SeedProd) persists its layout in ways the render-time pass cannot cover, so those
 * templates fall back to Classic-only. Detection is by template meta; the active builder wins over a
 * stale meta left behind by a migration, so unsupported builders are never mistaken for supported.
 *
 * A free service consumed by the creation/switch flows in later phases (no plan checks here).
 */
class LiveBuilderSupport
{
    public const BUILDER_ELEMENTOR = 'elementor';
    public const BUILDER_GUTENBERG = 'gutenberg';
    public const BUILDER_DIVI = 'divi';
    public const BUILDER_WPBAKERY = 'wpbakery';
    public const BUILDER_BRICKS = 'bricks';
    public const BUILDER_BREAKDANCE = 'breakdance';
    public const BUILDER_OXYGEN = 'oxygen';
    public const BUILDER_BRIZY = 'brizy';
    public const BUILDER_SEEDPROD = 'seedprod';

    private const SUPPORTED_BUILDERS = array(
        self::BUILDER_ELEMENTOR,
        self::BUILDER_GUTENBERG,
    );


    public function is_supported(int $template_id): bool
    {
        return in_array($this->detect_builder($template_id), self::SUPPORTED_BUILDERS, true);
    }

    /**
     * The active page builder of the template, as one of the BUILDER_* constants. Unsupported
     * builders are checked first so a stale Elementor/Gutenberg signal can't mask them; Gutenberg is
     * the fallback for a template with no recognised builder meta (classic editor included).
     */
    public function detect_builder(int $template_id): string
    {
        if ($this->meta_equals($template_id, '_et_pb_use_builder', 'on')) {
            return self::BUILDER_DIVI;
        }
        if ($this->meta_equals($template_id, '_wpb_vc_js_status', 'true')) {
            return self::BUILDER_WPBAKERY;
        }
        if ($this->has_meta($template_id, '_bricks_page_content_2')) {
            return self::BUILDER_BRICKS;
        }
        if ($this->has_meta($template_id, '_breakdance_data')) {
            return self::BUILDER_BREAKDANCE;
        }
        if ($this->has_meta($template_id, 'ct_builder_shortcodes') || $this->has_meta($template_id, 'ct_other_template')) {
            return self::BUILDER_OXYGEN;
        }
        if ($this->has_meta($template_id, 'brizy')) {
            return self::BUILDER_BRIZY;
        }
        if ($this->has_meta($template_id, '_seedprod_page')) {
            return self::BUILDER_SEEDPROD;
        }

        if ($this->meta_equals($template_id, '_elementor_edit_mode', 'builder') || $this->has_meta($template_id, '_elementor_data')) {
            return self::BUILDER_ELEMENTOR;
        }

        return self::BUILDER_GUTENBERG;
    }

    private function has_meta(int $template_id, string $key): bool
    {
        $value = get_post_meta($template_id, $key, true);
        return $value !== '' && $value !== null && $value !== false && $value !== array();
    }

    private function meta_equals(int $template_id, string $key, string $expected): bool
    {
        return get_post_meta($template_id, $key, true) === $expected;
    }
}
