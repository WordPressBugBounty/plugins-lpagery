<?php

namespace LPagery\service\live_render;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Delete guard for the Template Page of a live Page Set (ADR 0017). A Live Mode page persists only
 * a Stub Post; its design and content live on the Template Page and are proxied in at render time,
 * so the template is the only copy of what every live page of the set shows. Trashing or deleting
 * it would leave those pages with nothing to render.
 *
 * The guard is the decision seam behind the `pre_trash_post` / `pre_delete_post` filters in
 * {@see \LPagery\io\hooks\LiveRenderHooks}: it answers whether a post is a guarded template, builds
 * the block message the interactive admin flows die with, and classifies the current request as an
 * interactive flow or a programmatic caller (WP-CLI, cron, user deletion with content), which gets
 * the silent short-circuit plus one log line so a batch job is not aborted midway.
 *
 * A template counts as guarded while at least one live stub generated from it exists in **any** post
 * status: a trashed stub can be restored, and it would come back needing its template. The verdict
 * is derived per request and nothing is persisted, so the guard lifts by itself once the last live
 * page is gone. Free code with no plan check, like the rest of the render path (ADR 0009).
 */
class LiveTemplateDeleteGuard
{
    /** Pages-list and editor request actions that mean "the user asked to trash or delete this post". */
    private const DELETE_ACTIONS = array('trash', 'delete');

    private GeneratedPageRepository $repository;
    /** @var array<int,int> live page counts already looked up this request, keyed by template id. */
    private array $counts = array();
    private ?bool $live_stubs_exist = null;

    public function __construct(GeneratedPageRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * How many live pages render from $post_id, counting every post status. Zero for a classic
     * template and for a post LPagery never generated from. Memoised per request, and gated on the
     * cheap site-wide existence check so a site without Live Mode pays one query no matter how many
     * rows the Pages list shows.
     */
    public function count_live_pages(int $post_id): int
    {
        if ($post_id <= 0) {
            return 0;
        }
        if (isset($this->counts[$post_id])) {
            return $this->counts[$post_id];
        }
        if (!$this->live_stubs_exist()) {
            return $this->counts[$post_id] = 0;
        }
        return $this->counts[$post_id] = $this->repository->count_live_stubs_by_template($post_id);
    }

    /** True while at least one live page renders from $post_id, in any post status. */
    public function is_guarded_template(int $post_id): bool
    {
        return $this->count_live_pages($post_id) > 0;
    }

    /**
     * Everything the block message needs: the template's title, how many live pages render from it,
     * the two exits linked into the Manage screen (convert the pages to Classic, or delete the page
     * set) and the way back to where the request came from.
     *
     * Both exits live on the Manage screen. The Page Set id is carried in the URL so the screen can
     * open on the affected set, and `lpagery_action` names which of the two the user came for.
     *
     * @return array{post_id:int,template_title:string,live_page_count:int,convert_url:string,delete_url:string,back_url:string}
     */
    public function get_block_context(int $post_id): array
    {
        $process = $this->repository->get_process_id_by_template($post_id);
        $process_id = is_array($process) && isset($process['process_id']) ? (int)$process['process_id'] : 0;
        $manage_url = 'admin.php?page=lpagery&view=manage';
        if ($process_id > 0) {
            $manage_url .= '&process_id=' . $process_id;
        }

        $title = (string)get_the_title($post_id);
        if (trim($title) === '') {
            /* translators: %d is the numeric id of a page with no title. */
            $title = sprintf(__('page %d', 'lpagery'), $post_id);
        }

        $referer = wp_get_referer();
        $post_type = (string)get_post_type($post_id);
        $back_url = is_string($referer) && $referer !== ''
            ? $referer
            : admin_url('edit.php?post_type=' . ($post_type !== '' ? $post_type : 'page'));

        return array(
            'post_id' => $post_id,
            'template_title' => $title,
            'live_page_count' => $this->count_live_pages($post_id),
            'convert_url' => admin_url($manage_url . '&lpagery_action=convert_to_classic'),
            'delete_url' => admin_url($manage_url . '&lpagery_action=delete_process'),
            'back_url' => $back_url,
        );
    }

    /**
     * The block message as plain text: what LPagery kept, why, and the two ways out. Reused verbatim
     * as the JSON error the block editor shows in its toast.
     *
     * @param array{post_id:int,template_title:string,live_page_count:int,convert_url:string,delete_url:string,back_url:string} $context
     */
    public function get_block_message(array $context): string
    {
        $count = (int)$context['live_page_count'];

        return sprintf(
            /* translators: %1$s is the template page title, %2$d the number of live pages rendering from it. */
            _n(
                '"%1$s" is the template page for %2$d Live Mode page, so LPagery keeps it while that page exists. You can convert the page to Classic or delete the page set on the LPagery Manage screen.',
                '"%1$s" is the template page for %2$d Live Mode pages, so LPagery keeps it while those pages exist. You can convert those pages to Classic or delete the page set on the LPagery Manage screen.',
                $count,
                'lpagery'
            ),
            (string)$context['template_title'],
            $count
        );
    }

    /** The wp_die page title (browser title bar and JSON error code line). */
    public function get_block_title(): string
    {
        return __('Template page in use', 'lpagery');
    }

    /**
     * The block page body handed to wp_die for the interactive HTML flows: the message, the two
     * exits on the Manage screen and a link back to where the request came from.
     *
     * @param array{post_id:int,template_title:string,live_page_count:int,convert_url:string,delete_url:string,back_url:string} $context
     */
    public function render_block_page(array $context): string
    {
        return sprintf(
            '<h1>%s</h1><p>%s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p><p><a href="%s">%s</a></p>',
            esc_html__('This page is the template for live pages', 'lpagery'),
            esc_html($this->get_block_message($context)),
            esc_url((string)$context['convert_url']),
            esc_html__('Convert the pages to Classic', 'lpagery'),
            esc_url((string)$context['delete_url']),
            esc_html__('Delete the page set', 'lpagery'),
            esc_url((string)$context['back_url']),
            esc_html__('Go back', 'lpagery')
        );
    }

    /**
     * Report the refused trash/delete of $post_id to whoever asked for it. An interactive admin flow
     * stops on our own page (or, for JSON and REST requests, the plain message wp_die renders as a
     * JSON error, which the block editor shows in its toast). A programmatic caller gets one log
     * line and keeps running, so a WP-CLI script or a user deletion with content is not aborted
     * midway through its batch.
     */
    public function block(int $post_id): void
    {
        if (!$this->is_interactive_request()) {
            error_log(sprintf(
                'LPagery: kept template page %d because %d Live Mode pages render from it.',
                $post_id,
                $this->count_live_pages($post_id)
            ));
            return;
        }

        $context = $this->get_block_context($post_id);
        if ($this->is_json_request()) {
            // wp_die renders a JSON error for JSON and REST requests, which is what the block
            // editor shows in its toast, so the message goes in as plain text there.
            wp_die($this->get_block_message($context), $this->get_block_title(), array('response' => 403));
        } else {
            wp_die(
                $this->render_block_page($context),
                $this->get_block_title(),
                array('response' => 403, 'back_link' => false)
            );
        }
    }

    /**
     * Drop the Trash row action from a guarded template's row in the Pages/Posts list table, so the
     * list never offers an action that the guard then refuses. Every other action stays, and rows of
     * other posts are returned untouched.
     *
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public function filter_row_actions(array $actions, int $post_id): array
    {
        if (!$this->is_guarded_template($post_id)) {
            return $actions;
        }
        unset($actions['trash']);
        return $actions;
    }

    /**
     * Is the current request one of the interactive admin flows that should stop with our message,
     * rather than a programmatic caller that gets the silent short-circuit? Reads the request
     * environment and hands it to {@see is_interactive_flow()}, which holds the rule.
     */
    public function is_interactive_request(): bool
    {
        return $this->is_interactive_flow(
            (defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON),
            $this->is_json_request(),
            is_admin(),
            $_REQUEST
        );
    }

    /**
     * The rule behind {@see is_interactive_request()}, as a pure function of the request.
     *
     * Interactive: the Trash and Delete actions of the Pages list (single row and bulk, where the
     * bottom selector submits as `action2`) and of the editors, plus every JSON/REST request, which
     * is how the block editor deletes. Everything else is programmatic: WP-CLI and cron never show a
     * page to anyone, and a plain PHP caller (user deletion with content, a cleanup plugin, a
     * migration) must keep running instead of dying halfway through its batch.
     *
     * @param array<string,mixed> $request the request parameters, i.e. $_REQUEST
     */
    public function is_interactive_flow(bool $is_background, bool $is_json_request, bool $is_admin, array $request): bool
    {
        if ($is_background) {
            return false;
        }
        if ($is_json_request) {
            return true;
        }
        if (!$is_admin) {
            return false;
        }
        foreach (array('action', 'action2') as $key) {
            $action = isset($request[$key]) && is_string($request[$key]) ? strtolower($request[$key]) : '';
            if (in_array($action, self::DELETE_ACTIONS, true)) {
                return true;
            }
        }
        return false;
    }

    private function is_json_request(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        return function_exists('wp_is_json_request') && wp_is_json_request();
    }

    private function live_stubs_exist(): bool
    {
        return $this->live_stubs_exist ??= $this->repository->live_stubs_exist();
    }
}
