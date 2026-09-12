<?php

namespace LPagery\io\hooks;

/**
 * WordPress hook registrations for the Live Mode render pipeline (ADR 0007/0009/0010).
 *
 * Every front-end callback decides "not applicable" in a fixed order before it asks the composition
 * root for anything heavier: `is_admin()` (where the callback can run in wp-admin at all), then the
 * site-wide live gate `liveStubResolver()->live_stubs_exist()`, and only then the Live Render
 * controller / meta proxy. The gate is one request-memoised existence query, so a site without a
 * single Live Mode page never wires the Live Render object graph on a front-end request (#279). It
 * is data-driven, not a setting: the render path itself stays free and unconditional (ADR 0009).
 */
class LiveRenderHooks
{
    public static function register(): void
    {
        // Live Mode render pipeline (ADR 0007/0009/0010). Registered unconditionally in free code: the
        // render path carries no plan checks so existing live pages keep rendering if a license lapses.
        add_filter('get_post_metadata', [self::class, 'proxy_meta'], 10, 4);
        add_filter('the_posts', [self::class, 'swap_content'], 10, 2);
        add_action('template_redirect', [self::class, 'start_buffer']);

        // List-context image alt/title (ADR 0013, Phase 4): in archives, search, feeds and related
        // loops a live entry's featured image keeps the source attachment's static URL, but its
        // alt/title are substituted per entry from that entry's Row Data. The singular stub's own
        // image is handled by the output-buffer pass instead, so the handler skips it.
        add_filter('wp_get_attachment_image_attributes', [self::class, 'list_image_attributes'], 10, 2);

        // Deferred Shortcodes (ADR 0020): open the shortcode capture window at the very start of the
        // main stub's the_content chain — before a builder's own do_shortcode pass (priority 9) and
        // core's (11) — so every shortcode leaves a marker in the captured fragment instead of the
        // capturing page's output. The PHP_INT_MAX filter below closes the window again.
        add_filter('the_content', [self::class, 'open_capture_window'], PHP_INT_MIN, 1);

        // Render-fragment cache (ADR 0008): serve/capture the builder's pre-substitution content output.
        // Registered at PHP_INT_MAX so it runs after the builders (capturing their finished output) and
        // wins over them (serving the cached fragment on a hit).
        add_filter('the_content', [self::class, 'content_fragment'], PHP_INT_MAX, 1);

        // REST single-item substitution (ADR 0010, Phase 9): a REST read of a Live Mode stub gets its
        // rendered content/title/excerpt substituted per item (Row Data is unambiguous per post there).
        // Registered per public REST-enabled post type — pages and any custom post types LPagery
        // generates — after post types are registered. Edit-context reads are excluded inside the filter.
        add_action('init', [self::class, 'register_rest_filters'], 99);

        // Template-change propagation (ADR 0008): on saving a Template Page of one or more LIVE Page
        // Sets, drop its render fragment, bump post_modified on all affected live stubs (sitemap lastmod)
        // and purge cache plugins. Fires for every post save; a single indexed query no-ops for a normal
        // post. Elementor's editor save is hooked too — the handler dedupes per request so the two hooks
        // firing for one edit do the work once.
        add_action('save_post', [self::class, 'propagate_template_save'], 10, 1);
        add_action('elementor/editor/after_save', [self::class, 'propagate_template_save'], 10, 1);

        // Admin editing safety (ADR 0010, Phase 6). A Live Mode stub carries no editable content of its
        // own — its body renders live from the Template Page — so opening one in an editor would present
        // nothing to edit. The classic editor, Gutenberg and Elementor all enter through post.php, so a
        // single load-post.php hook intercepts every editor entry for a stub and shows an interstitial
        // (Edit Template / Convert to Classic / View) instead of the editor. Classic pages open normally.
        add_action('load-post.php', [self::class, 'admin_interstitial']);

        // Strip page-builder editor row actions ("Edit with Elementor" and friends) from stub rows in the
        // Pages/Posts list table, so the only edit path is core Edit → the interstitial. Runs after the
        // builders add their actions; classic pages are left untouched.
        add_filter('post_row_actions', [self::class, 'filter_stub_row_actions'], 20, 2);
        add_filter('page_row_actions', [self::class, 'filter_stub_row_actions'], 20, 2);

        // Template Page delete guard (ADR 0017). A live page renders from its Template Page, so
        // trashing or deleting the template would leave every page of the set with nothing to show.
        // Both filters short-circuit while live pages exist, the list table stops offering the Trash
        // action for such a row, and a restore from the trash clears what was cached meanwhile.
        // Free and unconditional like the rest of the render path: a lapsed license must not open a
        // hole that destroys pages.
        add_filter('pre_trash_post', [self::class, 'guard_template_deletion'], 10, 2);
        add_filter('pre_delete_post', [self::class, 'guard_template_deletion'], 10, 2);
        add_filter('post_row_actions', [self::class, 'filter_template_row_actions'], 20, 2);
        add_filter('page_row_actions', [self::class, 'filter_template_row_actions'], 20, 2);
        add_action('untrashed_post', [self::class, 'purge_restored_template'], 10, 1);

        // Deactivation warning (Phase 9): on the plugins screen, if any Live Mode pages exist, warn that
        // deactivating LPagery leaves them serving empty content until reactivation (the render pipeline
        // that fills them lives in this plugin). Rendered inline under the LPagery row (the slot core
        // uses for update messages), only when live stubs exist; a cheap COUNT gates it.
        $plugin_basename = plugin_basename(dirname(__FILE__, 4) . '/lpagery.php');
        add_action("after_plugin_row_{$plugin_basename}", [self::class, 'deactivation_warning']);

        // Live Mode admin warnings (issue #228 Phase 6, issue #248 Phase 3): on LPagery admin screens,
        // warn when the site serves site-wide Source URL Fallback (Endpoint Health failed, or plain
        // permalinks) and when live pages are orphaned by a trashed or deleted template page. LPagery
        // screens strip admin_notices (AdminMenuHooks::suppress_all_admin_notices at admin_head/10), so a
        // notice registered the normal way would be removed exactly where we want it. This re-adds them at
        // admin_head/11, after that suppression, scoped to LPagery screens, so they survive there and stay
        // absent everywhere else.
        add_action('admin_head', [self::class, 'maybe_show_live_admin_notices'], 11);

        add_action('save_post', [self::class, 'catch_manual_post_update'], 10, 3);
    }

    public static function proxy_meta($value, $object_id, $meta_key, $single)
    {
        // Cheap short-circuits before touching the pipeline, in order: no proxy in wp-admin, none on a
        // site without live pages. The meta hot path must stay O(1) per post per request.
        if (is_admin()) {
            return $value;
        }
        if (!lpagery_root()->liveStubResolver()->live_stubs_exist()) {
            return $value;
        }
        return lpagery_root()->liveMetaProxy()->filter($value, $object_id, $meta_key, $single);
    }

    public static function swap_content($posts, $query)
    {
        // Cheap short-circuits before touching the pipeline, in order: the render path never applies in
        // wp-admin, and never on a site without live pages, so neither request may wire the Live Render
        // graph to learn that.
        if (is_admin()) {
            return $posts;
        }
        if (!lpagery_root()->liveStubResolver()->live_stubs_exist()) {
            return $posts;
        }
        return lpagery_root()->liveRenderController()->swap_content($posts, $query);
    }

    public static function start_buffer()
    {
        // Front-end only hook, so no admin check: the site-wide live gate is the whole short-circuit
        // here, and it keeps the Live Render graph unwired on a site without a single live page.
        if (!lpagery_root()->liveStubResolver()->live_stubs_exist()) {
            return;
        }
        lpagery_root()->liveRenderController()->maybe_start_output_buffer();
    }

    public static function list_image_attributes($attr, $attachment)
    {
        // Cheap short-circuits before touching the pipeline, in order: list-context substitution is a
        // front-end concern and needs live pages, so neither an admin request nor a site without them
        // may wire the Live Render graph to learn that.
        if (is_admin()) {
            return $attr;
        }
        if (!lpagery_root()->liveStubResolver()->live_stubs_exist()) {
            return $attr;
        }
        if (!is_array($attr)) {
            return $attr;
        }
        $attachment_id = $attachment instanceof \WP_Post ? (int)$attachment->ID : 0;
        return lpagery_root()->liveRenderController()->filter_list_image_attributes($attr, $attachment_id);
    }

    public static function open_capture_window($content)
    {
        // Cheap short-circuits before touching the pipeline, in order: shortcodes are only deferred
        // while a front-end fragment is captured for a live page, so neither an admin request nor a
        // site without live pages may wire the Live Render graph for it. The controller decides
        // whether this the_content is the one to capture.
        if (is_admin()) {
            return $content;
        }
        if (!lpagery_root()->liveStubResolver()->live_stubs_exist()) {
            return $content;
        }
        return lpagery_root()->liveRenderController()->open_capture_window($content);
    }

    public static function content_fragment($content)
    {
        // Cheap short-circuits before touching the pipeline, in order: the render-fragment cache only
        // serves and captures front-end output for live pages, so neither an admin request nor a site
        // without them may wire the Live Render graph for it.
        if (is_admin()) {
            return $content;
        }
        if (!lpagery_root()->liveStubResolver()->live_stubs_exist()) {
            return $content;
        }
        return lpagery_root()->liveRenderController()->filter_content_fragment($content);
    }

    public static function register_rest_filters()
    {
        $post_types = get_post_types(array('public' => true, 'show_in_rest' => true), 'names');
        if (!in_array('page', $post_types, true)) {
            $post_types[] = 'page';
        }
        if (!in_array('post', $post_types, true)) {
            $post_types[] = 'post';
        }
        foreach ($post_types as $post_type) {
            add_filter("rest_prepare_{$post_type}", [self::class, 'rest_prepare'], 10, 3);
        }
    }

    public static function rest_prepare($response, $post, $request)
    {
        // REST-only hook, so no admin check: the site-wide live gate is the whole short-circuit here,
        // and it keeps the Live Render graph unwired on a site without a single live page.
        if (!lpagery_root()->liveStubResolver()->live_stubs_exist()) {
            return $response;
        }
        return lpagery_root()->liveRenderController()->filter_rest_response($response, $post, $request);
    }

    public static function propagate_template_save($post_id)
    {
        if (defined('DOING_LPAGERY_CREATION') && DOING_LPAGERY_CREATION) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        lpagery_root()->liveTemplateSaveHandler()->handle_template_save((int)$post_id);
    }

    public static function admin_interstitial()
    {
        $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
        if (!in_array($action, array('edit', 'elementor'), true)) {
            return;
        }
        $post_id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
            return;
        }
        $guard = lpagery_root()->liveStubAdminGuard();
        $context = $guard->get_interstitial_context($post_id);
        if ($context === null) {
            return;
        }

        global $title;
        $title = esc_html__('Live Mode page', 'lpagery');
        require_once ABSPATH . 'wp-admin/admin-header.php';
        echo $guard->render_interstitial($context);
        require_once ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }

    public static function filter_stub_row_actions($actions, $post)
    {
        if (!$post instanceof \WP_Post) {
            return $actions;
        }
        return lpagery_root()->liveStubAdminGuard()->filter_row_actions($actions, (int)$post->ID);
    }

    /**
     * `pre_trash_post` / `pre_delete_post`: refuse the removal of a Template Page while live pages
     * render from it. Returning false short-circuits WordPress, so the post keeps its status. The
     * guard also reports the refusal — our own page for the interactive admin flows, one log line
     * for a programmatic caller — before the value is returned; wp_die ends the request there.
     *
     * A decision another filter already made is left alone, and a guard failure never fatals the
     * request: WordPress then behaves as if the guard were not installed.
     *
     * @param null|bool $check
     * @param mixed $post
     * @return null|bool
     */
    public static function guard_template_deletion($check, $post)
    {
        if ($check !== null || !$post instanceof \WP_Post) {
            return $check;
        }
        try {
            $guard = lpagery_root()->liveTemplateDeleteGuard();
            if (!$guard->is_guarded_template((int)$post->ID)) {
                return $check;
            }
            $guard->block((int)$post->ID);
        } catch (\Throwable $e) {
            error_log('LPagery: live template delete guard failed for post ' . (int)$post->ID . ': ' . $e->getMessage());
            return $check;
        }
        return false;
    }

    /**
     * Drop the Trash row action from a guarded Template Page's row in the Pages/Posts list table, so
     * the list never offers what the guard then refuses.
     *
     * @param mixed $actions
     * @param mixed $post
     * @return mixed
     */
    public static function filter_template_row_actions($actions, $post)
    {
        if (!is_array($actions) || !$post instanceof \WP_Post) {
            return $actions;
        }
        return lpagery_root()->liveTemplateDeleteGuard()->filter_row_actions($actions, (int)$post->ID);
    }

    /**
     * `untrashed_post`: a Template Page coming back from the trash. Its live pages were served from
     * whatever was cached while it was gone, so the render fragment and those pages' caches are
     * dropped here.
     *
     * @param mixed $post_id
     */
    public static function purge_restored_template($post_id)
    {
        lpagery_root()->liveTemplateSaveHandler()->handle_template_restore((int)$post_id);
    }

    public static function deactivation_warning()
    {
        $warning = lpagery_root()->liveDeactivationWarning()->get_warning();
        if ($warning === null) {
            return;
        }
        $wp_list_table = _get_list_table('WP_Plugins_List_Table');
        printf(
            '<tr class="plugin-update-tr active"><td colspan="%d" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%s</p></div></td></tr>',
            (int) $wp_list_table->get_column_count(),
            esc_html($warning)
        );
    }

    public static function maybe_show_live_admin_notices()
    {
        // Only LPagery admin screens: the top-level dashboard (`toplevel_page_lpagery`) AND the submenu
        // pages (`{parent}_page_lpagery&view=*` — Create/Update/Manage/Views/Settings). Runs after the
        // admin_notices suppression, so re-registering the notices here lets them survive on these screens.
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, '_page_lpagery') === false) {
            return;
        }
        add_action('admin_notices', [self::class, 'degradation_warning']);
        add_action('admin_notices', [self::class, 'orphaned_live_pages_warning']);
    }

    public static function degradation_warning()
    {
        $warning = lpagery_root()->virtualImageDegradationWarning()->get_warning();
        if ($warning === null) {
            return;
        }
        // The service copy stays pure text; the help-center link (Virtual Image URLs → requirements) is
        // appended here for both degraded reasons so the article is the fix guidance.
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s" target="_blank" rel="noopener noreferrer" data-testid="virtual-images-degradation-help-link">%s</a></p></div>',
            esc_html($warning),
            esc_url('https://intercom.help/lpagery/en/articles/16818178#h_vi_requirements'),
            esc_html__('Learn more about the requirements for Virtual Image URLs', 'lpagery')
        );
    }

    public static function orphaned_live_pages_warning()
    {
        // Orphaned Live Pages (ADR 0017 §4): live pages whose template page is trashed or gone answer
        // visitors with a 404, and the stub carries no trace of it, so this notice is the only place the
        // user learns about them. The service copy stays pure text and is escaped here.
        $warning = lpagery_root()->orphanedLivePageWarning()->get_warning();
        if ($warning === null) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p data-testid="orphaned-live-pages-warning">%s</p></div>',
            esc_html($warning)
        );
    }

    public static function catch_manual_post_update($post_id, $post, $update)
    {
        // Thin hook → service seam. The tracker decides whether this save is a hand edit and stamps
        // the Manual Change; it is generation-type-agnostic, so quick-edits of Live Mode stubs are
        // tracked too (ADR 0010). Revisions and autosaves fire save_post as well; they are the same
        // edit under another post id, so they never count as a Manual Change of the page itself.
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $post_action = isset($_POST["action"]) ? (string)$_POST["action"] : null;
        $is_programmatic = (defined('DOING_CRON') && DOING_CRON)
            || (defined('DOING_LPAGERY_CREATION') && DOING_LPAGERY_CREATION);
        lpagery_root()->manualChangeTracker()->maybe_stamp(
            (int)$post_id,
            (bool)$update,
            $post_action,
            get_current_user_id(),
            !empty($_POST),
            $is_programmatic
        );
    }
}
