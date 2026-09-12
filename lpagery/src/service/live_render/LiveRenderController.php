<?php

namespace LPagery\service\live_render;

use Throwable;
use WP_Post;

/**
 * Front-end glue for the two render layers (ADR 0007): on a singular main-query render of a Live
 * Mode stub, swap its content fields to the Template Page's values (so builders/themes render the
 * design natively) and start an output buffer that runs one substitution pass over the final HTML.
 *
 * It also owns the Render Fragment's request state (ADR 0008) and, inside it, the Deferred Shortcode
 * capture window (ADR 0020): {@see self::open_capture_window()} arms the shortcode choke point at the
 * start of the main stub's `the_content` chain and {@see self::filter_content_fragment()} closes it,
 * stores what was held and expands it for the page being rendered.
 *
 * Both layers are strictly gated to singular main-query stub renders — archives, lists, admin and
 * non-LPagery pages fall through cheaply and render exactly as before. Failures degrade to the
 * unsubstituted output; the buffer callback can never white-screen the site.
 */
class LiveRenderController
{
    private const FRAGMENT_NONE = 'none';
    private const FRAGMENT_SERVE = 'serve';
    private const FRAGMENT_CAPTURE = 'capture';

    private LiveStubResolver $resolver;
    private LiveRenderPass $renderPass;
    private LiveBuilderCssHandler $builderCssHandler;
    private LiveFragmentCache $fragmentCache;
    private LiveShortcodeDeferral $deferral;
    private LiveRenderTelemetry $telemetry;
    private LiveListImageAttributes $listImageAttributes;
    private ?LiveStubData $activeStub = null;

    /** Render-fragment state for the current request (ADR 0008). */
    private string $fragmentMode = self::FRAGMENT_NONE;
    /** The fragment being served on a hit — HTML plus its Deferred Shortcodes (ADR 0020). */
    private ?LiveFragmentPayload $contentFragment = null;
    private string $templateModifiedGmt = '';
    private int $stubPostId = 0;
    private bool $fragmentCaptured = false;
    /** Nesting of the main stub's `the_content` inside an open capture window; only depth 1 owns it. */
    private int $captureChainDepth = 0;
    /** Whether this window armed Elementor's placeholder path, so exactly the same window disarms it. */
    private bool $elementorPlaceholderForced = false;
    /** Guard against a Deferred Shortcode re-entering `the_content` for the stub being rendered. */
    private bool $inFragmentFilter = false;
    /** @var array<int,array{callback:callable|string,priority:int}> builder the_content filters removed on a hit, to restore. */
    private array $removedContentFilters = array();

    public function __construct(LiveStubResolver $resolver, LiveRenderPass $renderPass, LiveBuilderCssHandler $builderCssHandler, LiveFragmentCache $fragmentCache, LiveShortcodeDeferral $deferral, LiveRenderTelemetry $telemetry, LiveListImageAttributes $listImageAttributes)
    {
        $this->resolver = $resolver;
        $this->renderPass = $renderPass;
        $this->builderCssHandler = $builderCssHandler;
        $this->fragmentCache = $fragmentCache;
        $this->deferral = $deferral;
        $this->telemetry = $telemetry;
        $this->listImageAttributes = $listImageAttributes;
    }

    /**
     * `the_posts` filter: on the singular main query for a stub, replace the (empty) stub content
     * fields with the Template Page's, leaving list/archive contexts untouched. An Orphaned Live
     * Page (ADR 0017) is left untouched too — it renders as a 404, not as a page.
     *
     * @param array<int,WP_Post> $posts
     * @param object             $query the WP_Query being run
     * @return array<int,WP_Post>
     */
    public function swap_content($posts, $query)
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_singular()) {
            return $posts;
        }
        if (count($posts) !== 1) {
            return $posts;
        }

        $post = $posts[0];
        $stub = $this->resolver->resolve((int)$post->ID);
        if ($stub === null || $stub->is_orphaned()) {
            return $posts;
        }

        $template = get_post($stub->template_id);
        if (!$template instanceof WP_Post) {
            return $posts;
        }

        $post->post_content = $template->post_content;
        $post->post_content_filtered = $template->post_content_filtered;
        $post->post_excerpt = $template->post_excerpt;

        return $posts;
    }

    /**
     * `wp_get_attachment_image_attributes` filter (ADR 0013, Phase 4): in a list context — theme
     * archive, search, feed, related-post loop — substitute a live entry's featured-image alt/title
     * from the current loop entry's Row Data, leaving the source attachment's static URL intact (one
     * shared, cacheable fetch). The current loop post supplies the entry; the pure seam
     * ({@see LiveListImageAttributes}) does the substitution, gated on the image being one of that
     * entry's virtual sources.
     *
     * The singular main-query stub is deliberately skipped: its own render surface is owned by the
     * output-buffer pass (Phase 3), which maps that image to a Virtual Image URL. Non-live posts fall
     * through cheaply via the resolver's site-wide gate.
     *
     * @param array<string,mixed> $attr          the image attributes core assembled for the image
     * @param int                 $attachment_id the source attachment being rendered
     * @return array<string,mixed> the attributes, alt/title substituted for a live-entry featured image
     */
    public function filter_list_image_attributes(array $attr, int $attachment_id): array
    {
        if (is_admin() || $attachment_id <= 0) {
            return $attr;
        }
        $entry_id = (int)get_the_ID();
        if ($entry_id <= 0) {
            return $attr;
        }
        // The singular stub's own image is remapped to a Virtual Image URL by the output-buffer pass;
        // only genuine list contexts (where the loop entry is not the queried singular post) run here.
        if (is_singular() && $entry_id === (int)get_queried_object_id()) {
            return $attr;
        }
        $stub = $this->resolver->resolve($entry_id);
        if ($stub === null) {
            return $attr;
        }
        return $this->listImageAttributes->substitute($attr, $attachment_id, $stub->row_data, $stub->attachment_pairs);
    }

    /**
     * `template_redirect` action: when a singular main-query render resolves a stub, remember its
     * Row Data and open the substitution output buffer.
     *
     * An Orphaned Live Page — its Template Page trashed or gone (ADR 0017) — never gets a buffer:
     * there is no design left to render, so the request becomes a real 404 instead.
     */
    public function maybe_start_output_buffer(): void
    {
        $stub = $this->resolve_singular_stub();
        if ($stub === null) {
            return;
        }
        if ($stub->is_orphaned()) {
            $this->send_orphaned_page_404();
            return;
        }
        $this->activeStub = $stub;
        $this->stubPostId = (int)get_queried_object_id();
        // Live renders only: make the template's builder CSS print inline so the OB pass can
        // substitute per-page image URLs inside it (no effect on classic renders — never reached).
        $this->builderCssHandler->maybe_inline_builder_css($this->stubPostId, $stub->template_id);
        $this->prepare_content_fragment($stub);
        ob_start(array($this, 'substitute_final_html'));
    }

    /**
     * Decide this request's render-fragment behaviour (ADR 0008). The fragment is the builder's
     * pre-substitution content output for the Template Page — identical across the whole Page Set and
     * free of page-specific physical scalars (title, canonical, permalink stay in the head/chrome,
     * which are rendered per request), so it is safe to share. On a HIT we serve the cached content
     * and skip the builder render; on a MISS we let the builder render and capture its output.
     *
     * The fragment is bypassed entirely — no read, no write — for requests where a shared, cacheable
     * content blob is unsafe or pointless (logged-in, preview, password-protected, non-GET, or a query
     * string carrying anything but tracking parameters), which fall through to the normal live render.
     */
    private function prepare_content_fragment(LiveStubData $stub): void
    {
        $this->fragmentMode = self::FRAGMENT_NONE;
        $this->contentFragment = null;
        $this->fragmentCaptured = false;

        if ($this->should_bypass_fragment()) {
            return;
        }

        $this->templateModifiedGmt = (string)get_post_field('post_modified_gmt', $stub->template_id);
        $fragment = $this->fragmentCache->get($stub->template_id, $this->templateModifiedGmt);
        if ($fragment !== null) {
            $this->fragmentMode = self::FRAGMENT_SERVE;
            $this->contentFragment = $fragment;
            $this->skip_builder_content_render();
            return;
        }
        $this->fragmentMode = self::FRAGMENT_CAPTURE;
    }

    /**
     * `the_content` filter, registered at the lowest priority so it runs before anything else in the
     * chain: open the Deferred Shortcode capture window (ADR 0020) for the main stub's content.
     *
     * From here until {@see self::filter_content_fragment()} closes the window at `PHP_INT_MAX`,
     * every shortcode WordPress expands — core's own pass at priority 11 and the ones a builder
     * expands itself at priority 9 — hands its text to the deferral through `pre_do_shortcode_tag`
     * and leaves a marker behind, so the captured fragment carries markers instead of the capturing
     * page's shortcode output.
     *
     * Only a capture opens a window: on a fragment HIT there is nothing to capture, and outside
     * fragment mode (logged-in, preview, query string) shortcodes render the ordinary WordPress way.
     * Secondary loops, other posts and every non-live render never reach past the first guard.
     *
     * @param string $content
     * @return string the content untouched — this filter only arms the choke point
     */
    public function open_capture_window($content)
    {
        if ($this->fragmentMode !== self::FRAGMENT_CAPTURE || !$this->is_main_stub_content()) {
            return $content;
        }
        // A nested `the_content` for the same stub (an excerpt helper, a "post content" widget) runs
        // inside the window the outer chain opened. It is only counted, so the closing filter can
        // tell an inner pass from the one that owns the window.
        $this->captureChainDepth++;
        if ($this->deferral->is_window_open()) {
            return $content;
        }
        $this->deferral->open_window();
        add_filter('pre_do_shortcode_tag', array($this->deferral, 'capture_shortcode'), 10, 4);
        if ($this->is_elementor_active()) {
            $this->elementorPlaceholderForced = true;
            add_filter('elementor/element/should_render_shortcode', array($this, 'force_elementor_placeholder'), 10, 1);
        }
        return $content;
    }

    /**
     * `elementor/element/should_render_shortcode` filter, attached for the capture window only
     * (ADR 0020 point 4). Verified against Elementor 4.2.4.
     *
     * Elementor asks this in `Element_Base::should_render_shortcode()`
     * (`includes/base/element-base.php:586`) and, when it is true, prints the element as
     * `[elementor-element k="…" data="…"]` instead of rendering it inline
     * (`element-base.php:487`). It answers false by default and only turns it on for itself around a
     * document cache miss (`core/base/document.php:1855`) — so with the element cache set to
     * `disable` (option `elementor_element_cache_ttl`) Elementor takes the early return in
     * `print_elements()` (`document.php:1835`) and every dynamic widget renders the *capturing*
     * page's title, image and URL straight into the shared Render Fragment. Forcing it true turns
     * those into placeholder shortcodes, which the Deferred Shortcode seam holds out of the fragment
     * and re-executes per request.
     *
     * Which elements this actually affects stays Elementor's decision, never a list of ours:
     * `should_render_shortcode()` still answers false for a widget that declares itself static
     * (`is_dynamic_content()`) and for any element pinned "Cache Settings: Active" (`_element_cache`
     * = `no`), so those keep rendering into the fragment exactly as before.
     *
     * Deliberately not `__return_true`: Elementor registers *that* callable at priority 10 itself and
     * removes it again at the end of its cache-miss branch (`document.php:1887`). Sharing the callable
     * would let Elementor's `remove_filter` take our window's filter with it mid-render.
     *
     * @param mixed $should_render_shortcode Elementor's current answer, always overridden
     */
    public function force_elementor_placeholder($should_render_shortcode): bool
    {
        return true;
    }

    /**
     * Disarm the shortcode choke point and close the capture window. Everything held stays held —
     * the caller still has to store and expand it — but no shortcode after this point is deferred.
     */
    private function close_capture_window(): void
    {
        remove_filter('pre_do_shortcode_tag', array($this->deferral, 'capture_shortcode'), 10);
        if ($this->elementorPlaceholderForced) {
            remove_filter('elementor/element/should_render_shortcode', array($this, 'force_elementor_placeholder'), 10);
            $this->elementorPlaceholderForced = false;
        }
        $this->deferral->close_window();
        $this->captureChainDepth = 0;
    }

    /**
     * `the_content` filter (registered late so it sees the builder's finished output and wins over
     * it): serve the cached content fragment on a HIT, or capture the builder output on a MISS. Only
     * acts on the main-loop render of the active stub — secondary loops, widgets and other posts pass
     * through untouched. Substitution still runs per request in the output-buffer pass, so the shared
     * fragment never leaks one page's Row Data into another.
     *
     * This is also the end of the capture window {@see self::open_capture_window()} opened: the
     * shortcode choke point is disarmed here and what it held is stored beside the HTML. Both paths
     * then expand those Deferred Shortcodes for the page being rendered (ADR 0020), so the shared
     * fragment carries markers and each request produces its own shortcode output.
     *
     * @param string $content
     * @return string
     */
    public function filter_content_fragment($content)
    {
        if ($this->fragmentMode === self::FRAGMENT_NONE || !is_string($content) || !$this->is_main_stub_content()) {
            return $content;
        }
        // Executing a Deferred Shortcode can run `the_content` for this same stub again (that is what
        // wp_trim_excerpt() does): serving or capturing there would expand the same shortcodes a
        // second time, on a hit without end.
        if ($this->inFragmentFilter) {
            return $content;
        }
        // An inner pass of the capture chain hands its content back as it is — markers included, for
        // the outer pass to expand — so that the window, the store and the expansion stay with the
        // one `the_content` that owns them.
        if ($this->fragmentMode === self::FRAGMENT_CAPTURE && $this->leave_inner_capture_pass()) {
            return $content;
        }
        $this->inFragmentFilter = true;
        try {
            return $this->serve_or_capture_fragment($content);
        } finally {
            $this->inFragmentFilter = false;
        }
    }

    /**
     * Account for one closing pass of the capture chain and say whether it is an inner one, i.e.
     * whether another `the_content` for this stub is still running around it.
     */
    private function leave_inner_capture_pass(): bool
    {
        if ($this->captureChainDepth > 0) {
            $this->captureChainDepth--;
        }
        return $this->captureChainDepth > 0;
    }

    /**
     * The body of {@see self::filter_content_fragment()}: serve the cached fragment, or close the
     * capture window, store what the builder produced and expand the Deferred Shortcodes for this
     * page.
     */
    private function serve_or_capture_fragment(string $content): string
    {
        $template_id = $this->activeStub !== null ? $this->activeStub->template_id : 0;
        if ($this->fragmentMode === self::FRAGMENT_SERVE) {
            // This runs at PHP_INT_MAX — the end of the main stub's the_content chain — so restore the
            // builder filters here: they were removed only to skip the render of THIS content, and any
            // later the_content in the request (footer widgets, related posts) must render normally.
            $this->restore_builder_content_render();
            $html = $this->contentFragment !== null ? $this->contentFragment->html : '';
            $shortcodes = $this->contentFragment !== null ? $this->contentFragment->shortcodes : array();
            $rescoped = $this->rescope_builder_document_ids($html, $template_id, $this->stubPostId);
            return $this->expand_deferred_shortcodes($rescoped, $shortcodes);
        }
        // Capture: a fragment is only ever stored for a render whose capture window was open, because
        // only then are the held texts complete. A builder that short-circuited before the window
        // opened leaves the content alone rather than baking its shortcode output into the fragment.
        if (!$this->deferral->is_window_open()) {
            return $content;
        }
        $shortcodes = $this->deferral->get_held_shortcodes();
        $this->close_capture_window();
        if (!$this->fragmentCaptured && $template_id > 0) {
            $this->fragmentCaptured = true;
            $canonical = $this->rescope_builder_document_ids($content, $this->stubPostId, $template_id);
            $this->fragmentCache->store($template_id, $canonical, $shortcodes, $this->templateModifiedGmt);
        }
        return $this->expand_deferred_shortcodes($content, $shortcodes);
    }

    /**
     * Run the Deferred Shortcodes of this request (ADR 0020): each held text is substituted with the
     * active stub's Row Data, executed, and put where its marker sits. The same path serves the
     * capture request and every fragment hit, so both pages of a set render their own output.
     *
     * @param array<string,string> $shortcodes marker => original shortcode text
     */
    private function expand_deferred_shortcodes(string $html, array $shortcodes): string
    {
        $stub = $this->activeStub;
        if ($shortcodes === array() || $stub === null) {
            return $html;
        }
        return $this->deferral->expand($html, $shortcodes, function (string $text) use ($stub): string {
            return $this->renderPass->substitute($text, $stub->row_data, $stub->attachment_pairs, $stub->spintax_enabled, $this->stubPostId, $stub->spin_seed);
        });
    }

    /**
     * Elementor stamps the rendering document's post id into its content wrapper (`data-elementor-id`,
     * the `elementor-{id}` class) — on a live render that is the *capturing stub's* id, which must not
     * leak into the shared fragment: every other stub of the set gets CSS scoped to its own id, so the
     * wrapper would never match. Fragments are therefore canonicalised to the Template id on capture
     * and rescoped to the serving stub's id on every hit. `(?!\d)` keeps an id from matching inside a
     * longer one; Gutenberg fragments contain neither pattern, so this is a cheap no-op for them.
     */
    private function rescope_builder_document_ids(string $html, int $from_id, int $to_id): string
    {
        if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
            return $html;
        }
        $rescoped = preg_replace(
            array(
                '/\belementor-' . $from_id . '(?!\d)/',
                '/(data-elementor-id=["\'])' . $from_id . '(["\'])/',
            ),
            array('elementor-' . $to_id, '${1}' . $to_id . '$2'),
            $html
        );
        return $rescoped ?? $html;
    }

    /**
     * On a fragment HIT, stop the supported builders from re-rendering the content they already
     * produced: the cached fragment is served by {@see self::filter_content_fragment()} instead. Only
     * the two Builders-v1 targets are neutralised; for anything else the late serve filter still wins,
     * so output stays correct even without the CPU saving. What is removed is recorded so
     * {@see self::restore_builder_content_render()} can put it back once the fragment is served.
     */
    private function skip_builder_content_render(): void
    {
        // Gutenberg: block rendering is a named the_content filter at priority 9.
        if (remove_filter('the_content', 'do_blocks', 9)) {
            $this->removedContentFilters[] = array('callback' => 'do_blocks', 'priority' => 9);
        }

        // Elementor: renders the builder into the_content from its own frontend instance, registered
        // at Frontend::THE_CONTENT_FILTER_PRIORITY (9) — remove_filter must match that priority or
        // the removal silently fails (remove_filter defaults to 10).
        if ($this->is_elementor_active() && class_exists('\\Elementor\\Plugin')) {
            $plugin = \Elementor\Plugin::$instance;
            if ($plugin !== null && isset($plugin->frontend)) {
                $priority = defined('\\Elementor\\Frontend::THE_CONTENT_FILTER_PRIORITY')
                    ? (int)constant('\\Elementor\\Frontend::THE_CONTENT_FILTER_PRIORITY')
                    : 9;
                $callback = array($plugin->frontend, 'apply_builder_in_content');
                if (remove_filter('the_content', $callback, $priority)) {
                    $this->removedContentFilters[] = array('callback' => $callback, 'priority' => $priority);
                }
            }
        }
    }

    private function restore_builder_content_render(): void
    {
        foreach ($this->removedContentFilters as $removed) {
            add_filter('the_content', $removed['callback'], $removed['priority']);
        }
        $this->removedContentFilters = array();
    }

    private function is_elementor_active(): bool
    {
        return did_action('elementor/loaded') > 0 || defined('ELEMENTOR_VERSION');
    }

    private function is_main_stub_content(): bool
    {
        return $this->stubPostId > 0 && in_the_loop() && (int)get_the_ID() === $this->stubPostId;
    }

    /**
     * Conservative guard: the fragment is only read/written for anonymous GET permalink requests.
     * Anything that can vary the rendered content per user or per query — logged-in views, previews,
     * password gates, non-GET methods — bypasses it and renders live. A query string bypasses too,
     * unless every one of its keys is a known tracking parameter ({@see LiveFragmentQueryGuard}):
     * campaign tags never reach the builder, so that traffic keeps the shared fragment.
     */
    private function should_bypass_fragment(): bool
    {
        if (is_user_logged_in() || is_preview()) {
            return true;
        }
        if ($this->stubPostId > 0 && post_password_required($this->stubPostId)) {
            return true;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET') {
            return true;
        }
        // Pagination and filter parameters can change what the builder renders, so anything beyond
        // tracking keys bypasses: safe by default, at the cost of not caching those variants.
        return !LiveFragmentQueryGuard::is_cacheable_query($_GET);
    }

    /**
     * Turn the current render into a real 404: the main query is marked as such before the theme
     * picks its template, so the theme's own 404 template renders, and the response carries the 404
     * status with no-cache headers so no proxy or page cache keeps the dead page around. Nothing is
     * written to the stub — the state is derived per request and lifts the moment the Template Page
     * comes back.
     */
    private function send_orphaned_page_404(): void
    {
        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->set_404();
        }
        status_header(404);
        nocache_headers();
    }

    /**
     * The stub a singular main-query render should substitute, or null when the current request is
     * not one (admin, non-singular, a non-stub post, or an Orphaned Live Page, which renders as a
     * 404 rather than being substituted).
     */
    public function get_singular_render_stub(): ?LiveStubData
    {
        $stub = $this->resolve_singular_stub();
        if ($stub === null || $stub->is_orphaned()) {
            return null;
        }
        return $stub;
    }

    /**
     * The stub of the current singular front-end render, orphaned or not, or null when this request
     * is not one (admin, non-singular, or a non-stub post).
     */
    private function resolve_singular_stub(): ?LiveStubData
    {
        if (is_admin() || !is_singular()) {
            return null;
        }
        return $this->resolver->resolve((int)get_queried_object_id());
    }

    /**
     * `rest_prepare_{post_type}` filter (ADR 0010): substitute a stub's rendered content/title/excerpt
     * per item, where the Row Data is unambiguous. Collections run this same filter per item, so their
     * physical scalars (title/excerpt) stay correct and any per-item content is substituted too.
     *
     * Edit-context requests are excluded entirely: the block editor round-trips content.rendered back
     * into the stub on save, and the stub must keep its empty content — substituted template content
     * must never be written back. Non-stub (classic/unrelated) posts pass through untouched.
     *
     * @param mixed  $response the WP_REST_Response being prepared
     * @param mixed  $post     the WP_Post the response is for
     * @param mixed  $request  the WP_REST_Request (ArrayAccess); `context` gates edit exclusion
     * @return mixed  the response, with rendered fields substituted for a non-edit stub read
     */
    public function filter_rest_response($response, $post, $request)
    {
        $context = isset($request['context']) ? (string)$request['context'] : '';
        if ($context === 'edit') {
            return $response;
        }
        if (!$post instanceof WP_Post) {
            return $response;
        }
        $stub = $this->resolver->resolve((int)$post->ID);
        // An Orphaned Live Page has no Template Page left to substitute from, so it is passed
        // through as the empty stub it is.
        if ($stub === null || $stub->is_orphaned()) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }
        foreach (array('content', 'title', 'excerpt') as $field) {
            if (isset($data[$field]['rendered']) && is_string($data[$field]['rendered'])) {
                $data[$field]['rendered'] = $this->renderPass->substitute($data[$field]['rendered'], $stub->row_data, $stub->attachment_pairs, $stub->spintax_enabled, (int)$post->ID, $stub->spin_seed);
            }
        }
        $response->set_data($data);
        return $response;
    }

    /**
     * Output-buffer callback: one substitution pass over the final HTML using the active stub's Row
     * Data. Wrapped so a failure serves the unsubstituted HTML rather than a white screen.
     *
     * @param string $html
     * @return string
     */
    public function substitute_final_html($html)
    {
        // The render surely ends here, so this is the last chance to disarm a capture window that
        // never reached the closing filter (a builder short-circuiting the_content, a loop that left
        // another post set up). Leaving `pre_do_shortcode_tag` attached would defer the rest of the
        // request's shortcodes into a window nobody expands, and whatever it already held is sitting
        // in the page as raw markers — so it is expanded here rather than served.
        $leftover = array();
        if ($this->deferral->is_window_open()) {
            $leftover = $this->deferral->get_held_shortcodes();
            $this->close_capture_window();
        }
        try {
            if ($this->activeStub === null || !is_string($html) || $html === '') {
                return $html;
            }
            $html = $this->expand_deferred_shortcodes($html, $leftover);
            return $this->renderPass->substitute($html, $this->activeStub->row_data, $this->activeStub->attachment_pairs, $this->activeStub->spintax_enabled, $this->stubPostId, $this->activeStub->spin_seed);
        } catch (Throwable $e) {
            error_log("LPagery live render output buffer failed: " . $e->getMessage());
            if ($this->activeStub !== null) {
                $this->telemetry->record_render_error($this->activeStub->template_id);
            }
            return $html;
        }
    }
}
