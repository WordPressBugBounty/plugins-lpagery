<?php

namespace LPagery\io\hooks;

class AdminAppHooks
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_scripts']);
        add_action('admin_init', [self::class, 'init_db']);
    }

    public static function enqueue_admin_scripts($page): void
    {
        if ($page !== 'toplevel_page_lpagery') {
            return;
        }

        // Path magic constants must resolve to the PLUGIN ROOT. In lpagery.php __DIR__/dirname(__FILE__)
        // were the plugin root; from src/io/hooks/ the root is dirname(__FILE__, 4) (hooks→io→src→root).
        lpagery_root()->viteAssetLoader()->enqueue(
            dirname(__FILE__, 4) . '/frontend/dist',
            'src/index.tsx',
            'lpagery_scripts',
            ['react', 'react-dom']
        );
        // Known Dao bypass: lpagery_app_tokens has no repository owner, so these existence checks query $wpdb directly.
        global $wpdb;

        $table_name_tokens = $wpdb->prefix . 'lpagery_app_tokens';
        $table_name_process = $wpdb->prefix . 'lpagery_process';
        $app_connected = $wpdb->get_var("SELECT EXISTS (SELECT * FROM $table_name_tokens)");
        $process_from_app_exists = $wpdb->get_var("SELECT EXISTS (SELECT * FROM $table_name_process WHERE managing_system = 'app')");

        $installationDateHandler = lpagery_root()->installationDateHandler();
        // Whether the site has ever generated anything. The dashboard decides its landing view before
        // it can fetch anything (issue #269), so the fact has to travel with the page rather than
        // arrive later over AJAX and move the tab under the reader.
        $has_page_sets = lpagery_root()->pageSetRepository()->count_processes() > 0;
        // 'wpml' | 'polylang' | null - the frontend only needs to know which one is driving language.
        $multilingual_plugin = lpagery_root()->multilingualPlugin();
        // The two `__premium_only` tier reads below are array VALUES, not `if` statements, so Freemius
        // leaves them in the free build, where the SDK's own `is_premium()` check answers false. That
        // is the one allowed non-`if` form here: both plan flags are plain booleans handed to the
        // shared frontend bundle, and neither names a class that the free build does not ship.
        $lpagery_scripts_object = array('is_free_plan' => (bool)lpagery_fs()->is_free_plan(),
            'is_premium_code' => (bool)lpagery_fs()->is_premium(),
            'has_features_enabled_license' => (bool)lpagery_fs()->has_features_enabled_license(),
            'is_extended_plan' => (bool)lpagery_fs()->is_plan_or_trial__premium_only("extended"),
            'is_standard_plan' => (bool)lpagery_fs()->is_plan_or_trial__premium_only("standard"),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce("lpagery_ajax"),
            'plugin_dir' => plugin_dir_url(dirname(__FILE__, 4)),
            'upload_dir' => wp_upload_dir(),
            'tracking_permissions' => (lpagery_root()->trackingPermissionService()->getPermissions()),
            'allowed_placeholders' => $installationDateHandler->get_placeholder_counts(),
            'max_pages_per_run' => $installationDateHandler->get_max_pages_per_run(),
            'version' => LPAGERY_VERSION,
            'username' => wp_get_current_user()->display_name,
            'app_connected' => (bool) $app_connected,
            'process_from_app_exists' => (bool) $process_from_app_exists,
            'has_page_sets' => $has_page_sets,
            'multilingual_plugin' => $multilingual_plugin === null ? null : $multilingual_plugin->plugin_key(),
            // Every configured language keyed by code, read once here so the UI can resolve a name
            // and a flag from a row's language code instead of asking the plugin per row. Cast to an
            // object so an empty map encodes as {} rather than [].
            'multilingual_languages' => (object)($multilingual_plugin === null ? array() : $multilingual_plugin->get_languages()));

// Encode the data as JSON and output it inline
        wp_add_inline_script('lpagery_scripts',
            'const lpagery_scripts_object = ' . wp_json_encode($lpagery_scripts_object) . ';', 'before');


    }

    public static function init_db(): void
    {
        lpagery_root()->databaseMigrator()->migrate();
    }
}
