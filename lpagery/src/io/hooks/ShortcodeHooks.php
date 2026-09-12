<?php

namespace LPagery\io\hooks;

class ShortcodeHooks
{
    public static function register(): void
    {
        add_shortcode('lpagery_urls', [self::class, 'urls']);
        add_shortcode('lpagery_link', [self::class, 'link']);
        add_shortcode('lpagery_view', [self::class, 'view']);
    }

    public static function urls($atts)
    {
        if (isset($atts["id"])) {
            $post_ids = lpagery_root()->generatedPageRepository()->get_published_posts_by_process($atts["id"]);
            if (!empty($post_ids)) {
                $list_items = '';
                foreach ($post_ids as $record) {
                    $post_id = $record->id;
                    $post_title = get_the_title($post_id);
                    $post_permalink = get_permalink($post_id);
                    $list_items .= "<li class='lpagery_created_page_item'><a class='lpagery_created_page_anchor' href='" . esc_url($post_permalink) . "'>" . esc_html($post_title) . "</a></li>";
                }
                return "<ul class='lpagery_created_page_list'>$list_items</ul>";
            }
        }
        return null;
    }

    public static function link($atts)
    {
        $post_id = get_the_ID();
        $plan_post_created = get_post_meta($post_id, '_lpagery_plan', true);

        if (!($plan_post_created === 'PRO' || lpagery_fs()->is_plan_or_trial('EXTENDED'))) {
            return null;
        }

        $slug = $atts['slug'] ?? null;
        $position = $atts['position'] ?? null;
        // `circle` is optional, so read it with a default: without one every [lpagery_link] that
        // omits it emitted an undefined-array-key warning on render.
        $circle = filter_var($atts['circle'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $title = $atts['title'] ?? null;
        $target = $atts['target'] ?? '_self';

        $allowed_targets = ['_blank',
            '_self',
            '_parent',
            '_top'];
        if (!in_array($target, $allowed_targets)) {
            $target = '_self'; // Default to _self if target is not valid
        }

        $found_post = null;

        if ($slug) {
            $slug = sanitize_title($slug);

            $found_post = lpagery_root()->generatedPageRepository()->get_post_by_slug_for_link($slug);

        } elseif ($position) {
            $position = sanitize_text_field($position);
            $allowed_positions = ['FIRST',
                'LAST',
                'NEXT',
                'PREV'];
            if (!in_array($position, $allowed_positions)) {
                return null;
            }
            $found_post = lpagery_root()->generatedPageRepository()->get_post_at_position_in_process($post_id, $position,
                $circle);
        }

        if ($found_post) {
            $title = empty($title) ? $found_post['post_title'] : $title;

            return '<a class="lpagery_link_anchor" href="' . esc_url(get_permalink($found_post['id'])) . '" target="' . esc_attr($target) . '">' . esc_html($title) . '</a>';
        }

        return null;
    }

    // Related-pages display View. Rendering is free (downgrade-safe, ADR-0002); creating/editing
    // a View is gated to Pro/Extended at the AJAX layer (ADR-0002). Resolves the View's match
    // value from the current page (or an explicit value="" override) and renders matching siblings.
    public static function view($atts)
    {
        $atts = shortcode_atts(array(
            'id' => null,
            'value' => null,
        ), $atts, 'lpagery_view');

        if (empty($atts['id'])) {
            return null;
        }

        $view_id = (int)$atts['id'];
        $current_post_id = get_the_ID();
        $current_post_id = $current_post_id ? (int)$current_post_id : null;
        $explicit_value = $atts['value'] !== null ? (string)$atts['value'] : null;

        // Numbered pagination is server-rendered; the current page comes from a per-View request
        // param so multiple Views on one page paginate independently (no "load more"/AJAX in v1).
        $page_param = \LPagery\controller\ViewController::pageParamFor($view_id);
        $page = isset($_GET[$page_param]) ? max(1, (int)$_GET[$page_param]) : 1;
        $base_url = self::current_url();

        $viewController = lpagery_root()->viewController();
        $html = $viewController->renderView($view_id, $current_post_id, $explicit_value, $page, $base_url);

        return $html !== "" ? $html : null;
    }

    /**
     * The current request URL, used as the base for numbered pagination links so they preserve
     * the page the View is embedded on plus any other query args.
     */
    public static function current_url()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        // Build the base from the site's configured origin (home_url) rather than the request's
        // Host header: a hostile or misrouted request could otherwise make pagination links point
        // at the wrong origin. The path/query still comes from the current request so the View's
        // embedding page and its other query args are preserved.
        $home = function_exists('home_url') ? (string)home_url() : '';
        $parts = $home !== '' ? wp_parse_url($home) : false;
        if (!is_array($parts) || empty($parts['host'])) {
            return $uri;
        }
        $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'http';
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $origin . $uri;
    }
}
