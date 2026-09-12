<?php

namespace LPagery\service\live_render;

/**
 * Makes per-page builder CSS flow through the output-buffer substitution pass on Live Mode renders,
 * so image URLs baked into builder CSS (e.g. Elementor section background images) get remapped to
 * the page's duplicated attachments (ADR 0007, "Builders v1").
 *
 * Elementor decides between an external `post-{id}.css` file and inline CSS from the *stored*
 * `_elementor_css` meta status — the `elementor_css_print_method` option is only consulted while
 * (re)generating CSS. On a live stub that meta is proxied to the template, whose status is `file`
 * on a default install, so Elementor would enqueue `post-{stub}.css` — a file that was never
 * generated — and the page loses all its widget styling. Two request-scoped shims fix this:
 *
 *  - `get_post_metadata` on the stub's `_elementor_css` read (after the meta proxy): rewrite the
 *    template's `file` meta to an `inline` one carrying the template's built CSS (read from its
 *    real `post-{template}.css` file), rescoped from `.elementor-{template}` to `.elementor-{stub}`
 *    to match the stub-scoped markup. A template meta that is already `inline` is rescoped too.
 *  - `pre_option_elementor_css_print_method` → `internal`: when Elementor does regenerate (empty
 *    meta, e.g. right after a cache clear), the fresh stub-scoped CSS is emitted inline as well.
 *
 * Inline CSS sits in the final document, so the OB pass substitutes per-page image URLs inside it.
 * Everything is guarded by Elementor being loaded — the handler is inert when it is not installed.
 *
 * Gutenberg needs no handling: core block styles and global styles are already printed inline in the
 * document head, so their image URLs are covered by the OB pass with no extra work — a no-op here.
 */
class LiveBuilderCssHandler
{
    private const ELEMENTOR_CSS_META_KEY = '_elementor_css';

    private LiveBuilderSupport $builderSupport;

    private int $stubPostId = 0;

    private int $templatePostId = 0;

    /** Set once this request's read reported empty to trigger Elementor's regeneration. */
    private bool $regenerationRequested = false;

    /** The inline CSS the meta shim served this request, for the late delivery check. */
    private string $servedInlineCss = '';

    public function __construct(LiveBuilderSupport $builderSupport)
    {
        $this->builderSupport = $builderSupport;
    }

    /**
     * On a live render whose template is an Elementor page, make Elementor emit the stub's CSS
     * inline for this request so the OB pass can substitute per-page image URLs inside it.
     */
    public function maybe_inline_builder_css(int $stub_post_id, int $template_id): void
    {
        if (!$this->is_elementor_active()) {
            return;
        }
        if ($this->builderSupport->detect_builder($template_id) !== LiveBuilderSupport::BUILDER_ELEMENTOR) {
            return;
        }
        $this->stubPostId = $stub_post_id;
        $this->templatePostId = $template_id;
        add_filter('pre_option_elementor_css_print_method', array($this, 'force_internal_css_print_method'));
        add_filter('get_post_metadata', array($this, 'filter_stub_css_meta'), PHP_INT_MAX, 3);
        // Block themes render the template HTML BEFORE `wp_head`, so an Elementor builder render in
        // the content attaches its inline CSS to the not-yet-registered `elementor-frontend` handle —
        // WordPress silently drops it, and Elementor's printed-guard blocks the later re-enqueue.
        // This late pass re-attaches the CSS this request actually served when that happened.
        add_action('wp_enqueue_scripts', array($this, 'ensure_inline_css_delivery'), 999);
    }

    /**
     * `pre_option_elementor_css_print_method` short-circuit: report Elementor's CSS print method as
     * `internal` (inline `<style>`) instead of the default `external` (per-post file).
     */
    public function force_internal_css_print_method(): string
    {
        return 'internal';
    }

    /**
     * `get_post_metadata` shim for the stub's `_elementor_css` read: serve the template's built CSS
     * as an `inline`-status meta rescoped to the stub, so Elementor prints it in the document
     * instead of linking a `post-{stub}.css` that does not exist.
     *
     * When the template's meta is unusable (absent — Elementor deletes it on every template save and
     * rebuilds lazily — or its built file is gone), the stub's *own* meta is served instead, but only
     * while it is fresher than the template: Elementor's regeneration saves its output as the stub's
     * own row, so that row is exactly the fresh CSS right after an update() and dangerously stale
     * CSS any later. A stale/absent own row yields an empty-status meta, which sends Elementor down
     * its regeneration path — made inline and stub-scoped by the print-method filter above.
     *
     * @param mixed  $value     the short-circuit value produced by earlier filters (the meta proxy's, normally)
     * @param int    $object_id the post whose meta is being read
     * @param string $meta_key  the meta key being read
     * @return mixed  a wrapped meta array for the stub's CSS read, otherwise $value unchanged
     */
    public function filter_stub_css_meta($value, $object_id, $meta_key)
    {
        if ($meta_key !== self::ELEMENTOR_CSS_META_KEY || (int)$object_id !== $this->stubPostId) {
            return $value;
        }

        // Read the template's meta directly (the template is not a stub, so this is not re-proxied).
        $template_meta = get_post_meta($this->templatePostId, self::ELEMENTOR_CSS_META_KEY, true);
        $status = is_array($template_meta) ? (string)($template_meta['status'] ?? '') : '';

        if ($status === 'inline') {
            $template_meta['css'] = $this->rescope_css((string)($template_meta['css'] ?? ''));
            $this->servedInlineCss = (string)$template_meta['css'];
            return array($template_meta);
        }
        if ($status === 'file') {
            $css = $this->read_template_css_file();
            if ($css !== null) {
                $template_meta['status'] = 'inline';
                $template_meta['css'] = $this->rescope_css($css);
                $this->servedInlineCss = (string)$template_meta['css'];
                return array($template_meta);
            }
        } elseif ($status === 'empty') {
            // The template genuinely has no CSS of its own.
            return array($template_meta);
        }

        $own_meta = $this->read_stub_own_meta();
        // After this shim has requested a regeneration, the own row is the regeneration's own output
        // (Elementor's update() saves there and re-reads through this filter before printing), so it
        // must be served regardless of the timestamp comparison — wall-clock skew against the
        // template's modified time would otherwise discard the CSS that was just built.
        if (is_array($own_meta) && (string)($own_meta['status'] ?? '') !== ''
            && ($this->regenerationRequested || (int)($own_meta['time'] ?? 0) >= $this->template_modified_timestamp())) {
            if ((string)($own_meta['status'] ?? '') === 'inline') {
                $this->servedInlineCss = (string)($own_meta['css'] ?? '');
            }
            return array($own_meta);
        }
        $this->regenerationRequested = true;
        return array(array('time' => 0, 'status' => '', 'css' => ''));
    }

    /**
     * `wp_enqueue_scripts` action at 999 — after Elementor registers (5) and enqueues (20) its
     * frontend styles: when the CSS this request served through the meta shim never made it into the
     * `elementor-frontend` handle's inline data (the block-theme early-render drop described in
     * {@see self::maybe_inline_builder_css()}), attach it now; with the handle unexpectedly absent,
     * fall back to printing a style tag at the end of `wp_head`.
     */
    public function ensure_inline_css_delivery(): void
    {
        if ($this->servedInlineCss === '') {
            return;
        }
        $after = wp_styles()->get_data('elementor-frontend', 'after');
        if (is_array($after) && in_array($this->servedInlineCss, $after, true)) {
            return;
        }
        if (wp_style_is('elementor-frontend', 'registered')) {
            wp_add_inline_style('elementor-frontend', $this->servedInlineCss);
            return;
        }
        add_action('wp_head', array($this, 'print_inline_css'), PHP_INT_MAX);
    }

    /**
     * Last-resort `wp_head` printer: the raw CSS in a style tag, mirroring Elementor's own inline
     * print (`Base::enqueue()` uses an unescaped printf for the same content).
     */
    public function print_inline_css(): void
    {
        printf('<style id="elementor-post-%d-css">%s</style>', $this->stubPostId, $this->servedInlineCss); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * The stub's own `_elementor_css` row, read straight from the meta cache so the whole
     * `get_post_metadata` filter chain is bypassed — this shim *and* the Live Mode meta proxy. The proxy
     * would otherwise hand back the template's row here: `_elementor_css` is not one of its own-meta
     * keys, and in the `file`-status-with-missing-file branch the template row does exist, so the stub
     * would inherit a `file` meta pointing at a CSS file Elementor never built for it.
     *
     * Same read `get_metadata_raw()` performs after its filter: prime the post-meta cache on a miss and
     * unserialize the single stored value; `''` when the stub has no such row.
     *
     * @return mixed
     */
    private function read_stub_own_meta()
    {
        $cache = wp_cache_get($this->stubPostId, 'post_meta');
        if (!is_array($cache)) {
            update_meta_cache('post', array($this->stubPostId));
            $cache = wp_cache_get($this->stubPostId, 'post_meta');
        }
        if (!is_array($cache) || !isset($cache[self::ELEMENTOR_CSS_META_KEY][0])) {
            return '';
        }
        return maybe_unserialize($cache[self::ELEMENTOR_CSS_META_KEY][0]);
    }

    private function template_modified_timestamp(): int
    {
        $modified = (string)get_post_field('post_modified_gmt', $this->templatePostId);
        $timestamp = strtotime($modified . ' +0000');
        return $timestamp === false ? 0 : $timestamp;
    }

    /**
     * The template's built CSS targets `.elementor-{template}` while the stub render's markup is
     * scoped `.elementor-{stub}` — rewrite the document-scope selectors. `(?!\d)` keeps a template
     * id from matching inside a longer id (e.g. template 30 vs an unrelated `.elementor-300`).
     */
    private function rescope_css(string $css): string
    {
        $rescoped = preg_replace('/\.elementor-' . $this->templatePostId . '(?!\d)/', '.elementor-' . $this->stubPostId, $css);
        return $rescoped ?? $css;
    }

    /**
     * @return string|null the contents of the template's built `post-{template}.css`, null when unreadable
     */
    private function read_template_css_file(): ?string
    {
        $upload_dir = wp_upload_dir();
        $basedir = is_array($upload_dir) ? (string)($upload_dir['basedir'] ?? '') : '';
        if ($basedir === '') {
            return null;
        }
        $path = $basedir . '/elementor/css/post-' . $this->templatePostId . '.css';
        if (!is_readable($path)) {
            return null;
        }
        $css = file_get_contents($path);
        return $css === false ? null : $css;
    }

    private function is_elementor_active(): bool
    {
        return did_action('elementor/loaded') > 0 || defined('ELEMENTOR_VERSION');
    }
}
