<?php

namespace LPagery\service\live_render;

/**
 * Admin-side safety for Live Mode Stub Posts (ADR 0010, Phase 6). A stub carries no editable content
 * of its own — its body lives on the Template Page and is substituted at render time — so opening one
 * in a block/classic/Elementor editor would let an admin "edit" a page whose content isn't there.
 *
 * This guard is the decision seam behind the wp-admin hooks in lpagery.php:
 *  - it answers "is post X a live stub?" (so the editor entry can be intercepted with an interstitial),
 *  - builds the interstitial's context + markup (Edit Template / Materialize / View), and
 *  - strips page-builder row actions ("Edit with Elementor" and friends) from stub rows in the list
 *    table, leaving core Edit (→ interstitial), Quick Edit, View and Trash intact.
 *
 * It never proxies template metas — the admin list table shows each stub's own physical scalars
 * (title, slug, status), matching {@see LiveMetaProxy}'s is_admin() short-circuit.
 */
class LiveStubAdminGuard
{
    /**
     * Row-action key substrings for the page builders whose editors must not open on a stub. Matched
     * case-insensitively against the action keys other plugins add via {post|page}_row_actions.
     */
    private const BUILDER_ACTION_NEEDLES = array(
        'elementor',
        'et_pb',
        'divi',
        'fl_builder',
        'beaver',
        'brizy',
        'oxygen',
        'bricks',
        'breakdance',
        'seedprod',
    );

    private LiveStubResolver $resolver;

    public function __construct(LiveStubResolver $resolver)
    {
        $this->resolver = $resolver;
    }


    public function is_live_stub(int $post_id): bool
    {
        return $post_id > 0 && $this->resolver->resolve($post_id) !== null;
    }

    /**
     * The data the editor-interception interstitial needs, or null when the post is not a live stub
     * (classic pages and non-LPagery posts fall through so their editor opens normally).
     *
     * @return array{post_id:int,template_id:int,template_edit_url:string,view_url:string,manage_url:string,edit_url:string,ajax_url:string,convert_nonce:string}|null
     */
    public function get_interstitial_context(int $post_id): ?array
    {
        if ($post_id <= 0) {
            return null;
        }
        $stub = $this->resolver->resolve($post_id);
        if ($stub === null) {
            return null;
        }

        return array(
            'post_id' => $post_id,
            'template_id' => $stub->template_id,
            'template_edit_url' => (string)get_edit_post_link($stub->template_id, 'url'),
            'view_url' => (string)get_permalink($post_id),
            'manage_url' => admin_url('admin.php?page=lpagery&view=manage'),
            // After materializing to classic the page opens in the normal editor (the interstitial
            // only intercepts live stubs), so send the admin straight there on success.
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'convert_nonce' => wp_create_nonce('lpagery_ajax'),
        );
    }

    /**
     * The interstitial body (inside a `.wrap`) shown instead of the editor when an admin opens a live
     * stub. Plain WP admin markup — the caller wraps it with the admin header/footer chrome. The
     * "Materialize to Classic Mode" action POSTs to the conversion endpoint with the lpagery_ajax
     * nonce and, on success, sends the admin to the now-classic page's editor. Materialize is free on
     * every tier (ADR 0019), so the button is shown unconditionally.
     *
     * @param array{post_id:int,template_id:int,template_edit_url:string,view_url:string,manage_url:string,edit_url:string,ajax_url:string,convert_nonce:string} $context
     */
    public function render_interstitial(array $context): string
    {
        $template_edit_url = esc_url($context['template_edit_url']);
        $view_url = esc_url($context['view_url']);
        $post_id = (int)($context['post_id'] ?? 0);
        $ajax_url = esc_url($context['ajax_url'] ?? admin_url('admin-ajax.php'));
        $edit_url = esc_url($context['edit_url'] ?? '');
        $convert_nonce = esc_attr($context['convert_nonce'] ?? '');

        ob_start();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('This page renders live from its template page', 'lpagery'); ?></h1>
            <div class="card" style="max-width:40rem;padding:1rem 1.25rem;">
                <p>
                    <?php esc_html_e('This is a Live Mode page. LPagery renders it from its template page when someone opens it, so the page itself has no content to edit.', 'lpagery'); ?>
                </p>
                <p>
                    <a href="<?php echo $template_edit_url; ?>" class="button button-primary"><?php esc_html_e('Edit Template', 'lpagery'); ?></a>
                    <button type="button" class="button" id="lpagery-convert-to-classic"
                            data-post-id="<?php echo $post_id; ?>"
                            data-nonce="<?php echo $convert_nonce; ?>"
                            data-ajax-url="<?php echo $ajax_url; ?>"
                            data-edit-url="<?php echo $edit_url; ?>"><?php esc_html_e('Materialize to Classic Mode', 'lpagery'); ?></button>
                    <a href="<?php echo $view_url; ?>" class="button"><?php esc_html_e('View page', 'lpagery'); ?></a>
                </p>
                <p class="description">
                    <?php esc_html_e('To change what this page shows, edit its template page. To edit this one page on its own, materialize it to Classic Mode. That copies the current design and content onto the page so you can edit it directly.', 'lpagery'); ?>
                    <a href="https://intercom.help/lpagery/en/articles/16818173#h_live_edit" target="_blank" rel="noopener noreferrer" data-testid="live-stub-help-link"><?php esc_html_e('Learn more about editing Live Mode pages', 'lpagery'); ?></a>
                </p>
                <p class="description" id="lpagery-convert-error" style="display:none;color:#b32d2e;"></p>
            </div>
        </div>
        <script>
            (function () {
                var button = document.getElementById('lpagery-convert-to-classic');
                if (!button) {
                    return;
                }
                button.addEventListener('click', function () {
                    var errorEl = document.getElementById('lpagery-convert-error');
                    button.disabled = true;
                    var originalLabel = button.textContent;
                    button.textContent = <?php echo wp_json_encode(esc_html__('Materializing…', 'lpagery')); ?>;
                    if (errorEl) {
                        errorEl.style.display = 'none';
                    }

                    var body = new URLSearchParams();
                    body.append('action', 'lpagery_convert_page_render_mode');
                    body.append('_wpnonce', button.getAttribute('data-nonce'));
                    body.append('post_id', button.getAttribute('data-post-id'));
                    body.append('direction', 'classic');

                    fetch(button.getAttribute('data-ajax-url'), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    }).then(function (response) {
                        return response.json();
                    }).then(function (result) {
                        if (result && result.success) {
                            window.location.href = result.edit_url || button.getAttribute('data-edit-url');
                            return;
                        }
                        throw new Error(result && result.exception ? result.exception : 'Materializing the page failed');
                    }).catch(function (error) {
                        button.disabled = false;
                        button.textContent = originalLabel;
                        if (errorEl) {
                            errorEl.textContent = error.message;
                            errorEl.style.display = 'block';
                        }
                    });
                });
            })();
        </script>
        <?php
        return (string)ob_get_clean();
    }

    /**
     * Remove page-builder editor row actions from a stub's row in the Pages/Posts list table. Core
     * "Edit" is left in place (it points at the interstitial), as are Quick Edit, View and Trash.
     * Classic pages and non-LPagery posts are returned untouched.
     *
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public function filter_row_actions(array $actions, int $post_id): array
    {
        if (!$this->is_live_stub($post_id)) {
            return $actions;
        }

        foreach (array_keys($actions) as $key) {
            $lower_key = strtolower((string)$key);
            foreach (self::BUILDER_ACTION_NEEDLES as $needle) {
                if (strpos($lower_key, $needle) !== false) {
                    unset($actions[$key]);
                    break;
                }
            }
        }

        return $actions;
    }
}
