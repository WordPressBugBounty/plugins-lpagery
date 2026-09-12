<?php

namespace LPagery\io\hooks;

use LPagery\io\Mapper;
use LPagery\service\settings\SettingsController;

class AdminListTableHooks
{
    public static function register(): void
    {
        add_filter('posts_where', [self::class, 'filter_source'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'render_reset_filter_button']);
        add_filter('posts_where', [self::class, 'filter_hide_generated'], 10, 2);
        add_filter('wp_count_posts', [self::class, 'adjust_post_counts'], 10, 3);
        add_action('transition_post_status', [self::class, 'invalidate_generated_page_counts_on_transition'], 10, 3);
        add_action('deleted_post', [self::class, 'invalidate_generated_page_counts_on_delete'], 10, 1);
        add_action('admin_footer', [self::class, 'filter_text_process']);
        add_action('admin_footer', [self::class, 'filter_text_template_post']);
        add_filter('the_posts', [self::class, 'batch_load_template_data'], 10, 2);
        add_filter('post_row_actions', [self::class, 'add_export_row_action'], 2, 2);
        add_filter('page_row_actions', [self::class, 'add_export_row_action'], 2, 2);
    }

    public static function filter_source($where, $query)
    {
        global $wpdb;

        // Only apply filter on admin post listing pages
        if (!is_admin() || !$query->is_main_query()) {
            return $where;
        }

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        if (!isset($_GET['lpagery_process']) && !isset($_GET['lpagery_template'])) {
            return $where;
        }

        if (isset($_GET['lpagery_template'])) {
            $lpagery_template_id = $_GET['lpagery_template'];
            if ($lpagery_template_id != '') {
                $lpagery_template_id = intval($lpagery_template_id);
                $where .= $wpdb->prepare(" AND EXISTS (
                    SELECT pp.id 
                    FROM $table_name_process_post pp
                    WHERE pp.template_id = %d 
                    AND pp.post_id = $wpdb->posts.id
                )", $lpagery_template_id);
            }
        } else {
            $lpagery_process_id = $_GET['lpagery_process'];
            if ($lpagery_process_id != '') {
                $lpagery_process_id = intval($lpagery_process_id);
                $where .= $wpdb->prepare(" AND EXISTS (
                    SELECT pp.id
                    FROM $table_name_process_post pp
                    WHERE pp.lpagery_process_id = %d
                    AND pp.post_id = $wpdb->posts.id
                )", $lpagery_process_id);
            }
        }

        return $where;
    }

    public static function render_reset_filter_button()
    {

        ?>
        <input id="lpagery_reset_filter" class="button" type="button" value="Reset LPagery Filter"
               style="display: none">
        <?php
    }

    public static function filter_hide_generated($where, $query)
    {
        global $wpdb;

        // Only apply if the setting is enabled
        if (!lpagery_root()->settingsController()->isHideGeneratedPagesEnabled()) {
            return $where;
        }

        // Only apply filter on admin post listing pages
        if (!is_admin() || !$query->is_main_query()) {
            return $where;
        }

        // Don't filter when user is intentionally viewing LPagery pages
        if (isset($_GET['lpagery_process']) || isset($_GET['lpagery_template'])) {
            return $where;
        }

        // Only apply on edit screens
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, ['edit'])) {
            return $where;
        }

        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';

        // Exclude posts that are LPagery-generated
        $where .= " AND NOT EXISTS (
            SELECT 1 
            FROM $table_name_process_post lpp 
            WHERE lpp.post_id = $wpdb->posts.ID
        )";

        return $where;
    }

    /**
     * Subtract the Generated Pages from the per-status counts WordPress shows, so the "At a Glance"
     * dashboard widget and the list-table status links agree with the filtered listing.
     *
     * WordPress fires `wp_count_posts` several times per admin request, so the counts come from
     * {@see \LPagery\data\repository\GeneratedPageRepository::get_generated_page_counts_by_status()},
     * which memoizes per request and caches across requests instead of re-running the join (#275).
     */
    public static function adjust_post_counts($counts, $type, $perm)
    {
        // Only apply if the setting is enabled
        if (!lpagery_root()->settingsController()->isHideGeneratedPagesEnabled()) {
            return $counts;
        }

        // Only adjust in admin
        if (!is_admin()) {
            return $counts;
        }

        // Don't adjust when viewing LPagery pages
        if (isset($_GET['lpagery_process']) || isset($_GET['lpagery_template'])) {
            return $counts;
        }

        $lpagery_counts = lpagery_root()->generatedPageRepository()->get_generated_page_counts_by_status((string)$type);

        // Subtract LPagery counts from the total counts
        foreach ($lpagery_counts as $status => $count) {
            if (isset($counts->$status)) {
                $counts->$status = max(0, $counts->$status - $count);
            }
        }

        return $counts;
    }

    /**
     * Drop the cached Generated Page counts when a post changes status outside LPagery — someone
     * trashing, publishing or restoring a Generated Page by hand shifts the per-status totals the
     * "Hide generated pages" adjustment subtracts (#275).
     *
     * `transition_post_status` also fires for saves that keep the status, which change no total,
     * so those are ignored.
     *
     * @param string $new_status
     * @param string $old_status
     * @param mixed $post
     */
    public static function invalidate_generated_page_counts_on_transition($new_status, $old_status, $post): void
    {
        if ($new_status === $old_status) {
            return;
        }

        lpagery_root()->generatedPageRepository()->invalidate_generated_page_counts();
    }

    /**
     * Drop the cached Generated Page counts when a post is deleted for good. A permanent delete
     * emits no status transition, so it needs its own hook (#275).
     *
     * @param mixed $post_id
     */
    public static function invalidate_generated_page_counts_on_delete($post_id): void
    {
        lpagery_root()->generatedPageRepository()->invalidate_generated_page_counts();
    }

    public static function filter_text_process()
    {
        if (!isset($_GET['lpagery_process'])) {
            return;
        }
        $lpagery_process_id = intval($_GET['lpagery_process']);
        if (!$lpagery_process_id) {
            return;
        }

        $process = lpagery_root()->pageSetRepository()->get_process_by_id($lpagery_process_id);

        if (empty($process)) {
            return;
        }
        $mapper = lpagery_root()->mapper();
        $process = $mapper->lpagery_map_process($process);
        $post_id = $process["post_id"];
        $purpose = $process["display_purpose"];
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post->ID)) {
            return;
        }
        $post_title = $post->post_title;
        $permalink = get_permalink($post_id);
        if ($post_title) {
            ?>
            <script>
                jQuery(function ($) {
                    let test = $('<span><?php echo esc_js($purpose); ?> with Template: <a href="<?php echo esc_url($permalink); ?>"> <?php echo esc_js($post_title); ?><a/></span')
                    $('<div style="margin-bottom:5px;"></div>').append(test).insertAfter('#wpbody-content .wrap h2:eq(0)');
                });
            </script><?php
        }
    }

    public static function filter_text_template_post()
    {
        if (!isset($_GET['lpagery_template'])) {
            return;
        }
        $lpagery_template_id = intval($_GET['lpagery_template']);
        if (!$lpagery_template_id) {
            return;
        }

        $post = get_post($lpagery_template_id);
        if (!$post || !current_user_can('edit_post', $post->ID)) {
            return;
        }

        $post_title = $post->post_title;
        $permalink = get_permalink($post);
        if ($post_title) {
            ?>
            <script>
                jQuery(function ($) {
                    let test = $('<span>Show all created pages with Template: <a href="<?php echo esc_url($permalink); ?>"> <?php echo esc_js($post_title); ?><a/></span')
                    $('<div style="margin-bottom:5px;"></div>').append(test).insertAfter('#wpbody-content .wrap h2:eq(0)');
                });
            </script><?php
        }
    }

    public static function add_export_row_action($actions, \WP_Post $post)
    {
        $post_id = $post->ID;

        // Use cached data if available, otherwise fall back to database query
        $process_id = null;
        if (isset($GLOBALS['lpagery_template_cache'][$post_id])) {
            // Cache hit: process_id set = template, null = not a template (batch already checked)
            $process_id = $GLOBALS['lpagery_template_cache'][$post_id]['process_id'];
        } else {
            // Fallback for single post views or when batch cache was not populated
            $process_id_result = lpagery_root()->generatedPageRepository()->get_process_id_by_template($post_id);
            if ($process_id_result) {
                $process_id = $process_id_result["process_id"];
            }
        }

        if ($process_id) {
            $nonce = wp_create_nonce("lpagery_ajax");

            $actions['lpagery_export_page'] = sprintf('<a href="%1$s" target="_blank">%2$s</a>', get_admin_url(null,
                    'admin-ajax.php') . '?action=lpagery_download_post_json&process_id=' . $process_id . '&_ajax_nonce=' . $nonce,
                __('LPagery: Export Template Page', 'lpagery'));
        }

        return $actions;

    }

    public static function batch_load_template_data($posts, $query)
    {
        global $wpdb;
        static $template_cache = array();

        // Only in admin and main query
        if (!is_admin() || !$query->is_main_query()) {
            return $posts;
        }

        // Only on edit screens
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, ['edit'])) {
            return $posts;
        }

        if (empty($posts)) {
            return $posts;
        }

        // Collect all post IDs
        $post_ids = array_map(function($post) {
            return $post->ID;
        }, $posts);

        if (empty($post_ids)) {
            return $posts;
        }

        // Batch query to get template data
        $table_name_process_post = $wpdb->prefix . 'lpagery_process_post';
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $query_string = $wpdb->prepare(
            "SELECT template_id, lpagery_process_id FROM $table_name_process_post WHERE template_id IN ($placeholders) GROUP BY template_id",
            ...$post_ids
        );
        $results = $wpdb->get_results($query_string);

        // Cache results: templates get process_id, non-templates get null
        $template_ids_from_results = array();
        foreach ($results as $row) {
            $template_cache[$row->template_id] = array(
                'process_id' => $row->lpagery_process_id
            );
            $template_ids_from_results[$row->template_id] = true;
        }

        // Mark post IDs that were checked but are NOT templates (avoids fallback queries)
        foreach ($post_ids as $post_id) {
            if (!isset($template_ids_from_results[$post_id])) {
                $template_cache[$post_id] = array('process_id' => null);
            }
        }

        // Store in a global for access in row action filter
        $GLOBALS['lpagery_template_cache'] = $template_cache;

        return $posts;
    }
}
