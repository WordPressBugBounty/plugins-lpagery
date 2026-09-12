<?php

namespace LPagery\io\asset;

use WP_HTML_Tag_Processor;

/**
 * Vendored Vite/WordPress asset loader.
 *
 * Replaces Kucrut\Vite\enqueue_asset() for LPagery's single wp-admin entry point. It
 * reproduces the runtime behaviour the admin app depends on for our one call site:
 *  - dev mode (a `vite-dev-server.json` hot file is present in the dist dir): enqueue
 *    `@vite/client`, inject the React Refresh preamble when the hot file advertises the
 *    `vite:react-refresh` plugin, and enqueue the entry from the dev-server origin, all as
 *    ES modules;
 *  - prod mode (only `manifest.json`): enqueue the hashed entry JS and every CSS file on
 *    the entry and, recursively, its imports.
 *
 * Options that upstream exposes but this call site never uses (css-only, custom
 * css-dependencies, the filter/hook extension points, deprecated paths) are intentionally
 * dropped. Behaviour for our call is identical.
 */
class ViteAssetLoader
{
    private const VITE_CLIENT_HANDLE = 'vite-client';

    /** Guards the one-shot React Refresh preamble so it is only injected once per request. */
    private bool $reactRefreshPreamblePrinted = false;

    /**
     * Register and enqueue the entry (and its styles/deps) for the given dist directory.
     *
     * @param string   $dist_dir     Absolute path to the directory holding the manifest / hot file.
     * @param string   $entry        Manifest entry key (e.g. `src/index.tsx`).
     * @param string   $handle       Script handle for the entry.
     * @param string[] $dependencies WordPress script dependencies for the entry.
     *
     * @return bool True when assets were enqueued, false on a graceful no-op.
     */
    public function enqueue(string $dist_dir, string $entry, string $handle, array $dependencies): bool
    {
        $hot_file = "{$dist_dir}/vite-dev-server.json";

        if (is_file($hot_file) && is_readable($hot_file)) {
            return $this->enqueue_development($hot_file, $entry, $handle, $dependencies);
        }

        return $this->enqueue_production($dist_dir, $entry, $handle, $dependencies);
    }

    private function enqueue_development(string $hot_file, string $entry, string $handle, array $dependencies): bool
    {
        $hot = $this->read_json($hot_file);

        if (!is_array($hot) || !isset($hot['origin'], $hot['base'])) {
            return $this->fail("[Vite] Invalid hot file {$hot_file}.");
        }

        $origin = (string) $hot['origin'];
        $base = (string) $hot['base'];
        $plugins = isset($hot['plugins']) && is_array($hot['plugins']) ? $hot['plugins'] : [];

        $this->register_vite_client($origin, $base);
        $this->inject_react_refresh_preamble($origin, $base, $plugins);

        $src = $this->dev_asset_src($origin, $base, $entry);
        $deps = array_merge([self::VITE_CLIENT_HANDLE], $dependencies);

        $this->filter_script_tag($handle);
        // Development script; browsers shouldn't cache it, so the version stays null.
        if (!wp_register_script($handle, $src, $deps, null, true)) {
            return false;
        }

        wp_enqueue_script($handle);

        return true;
    }

    private function enqueue_production(string $dist_dir, string $entry, string $handle, array $dependencies): bool
    {
        $manifest_file = "{$dist_dir}/manifest.json";

        if (!is_file($manifest_file) || !is_readable($manifest_file)) {
            return $this->fail("[Vite] No manifest found in {$dist_dir}.");
        }

        $manifest = $this->read_json($manifest_file);

        if (!is_array($manifest)) {
            return $this->fail("[Vite] Failed to read manifest file {$manifest_file}.");
        }

        if (!isset($manifest[$entry]) || !is_array($manifest[$entry])) {
            return $this->fail("[Vite] Entry {$entry} not found.");
        }

        if (!isset($manifest[$entry]['file']) || !is_string($manifest[$entry]['file']) || $manifest[$entry]['file'] === '') {
            return $this->fail("[Vite] Entry {$entry} has no file.");
        }

        $url = $this->prepare_asset_url($dist_dir);
        $item = $manifest[$entry];

        $this->filter_script_tag($handle);
        // The hash is embedded in the file name, so no version query is needed.
        if (wp_register_script($handle, "{$url}/{$item['file']}", $dependencies, null, true)) {
            wp_enqueue_script($handle);
        }

        $style_handles = [];
        $this->collect_styles($manifest, $item, $url, $handle, $style_handles);

        foreach ($style_handles as $style_handle) {
            wp_enqueue_style($style_handle);
        }

        return true;
    }

    /**
     * Register the CSS on the entry and, recursively, on its imports.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $item
     * @param string[]             $style_handles
     */
    private function collect_styles(array $manifest, array $item, string $url, string $handle, array &$style_handles): void
    {
        if (!empty($item['imports']) && is_array($item['imports'])) {
            foreach ($item['imports'] as $import) {
                if (isset($manifest[$import]) && is_array($manifest[$import])) {
                    $this->collect_styles($manifest, $manifest[$import], $url, $handle, $style_handles);
                }
            }
        }

        if (!empty($item['css']) && is_array($item['css'])) {
            $this->register_stylesheets($item['css'], $url, $handle, $style_handles);
        }
    }

    /**
     * @param string[] $stylesheets
     * @param string[] $style_handles
     */
    private function register_stylesheets(array $stylesheets, string $url, string $handle, array &$style_handles): void
    {
        foreach ($stylesheets as $css_file_path) {
            $filename = pathinfo((string) $css_file_path, PATHINFO_FILENAME);
            $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $filename), '-'));
            // The slug ties the handle to the css file so it is never registered twice.
            $style_handle = "{$handle}-{$slug}";

            if (wp_register_style($style_handle, "{$url}/{$css_file_path}", [], null, 'all')) {
                $style_handles[] = $style_handle;
            }
        }
    }

    private function register_vite_client(string $origin, string $base): void
    {
        if (wp_script_is(self::VITE_CLIENT_HANDLE)) {
            return;
        }

        $src = $this->dev_asset_src($origin, $base, '@vite/client');
        wp_register_script(self::VITE_CLIENT_HANDLE, $src, [], null, false);
        $this->filter_script_tag(self::VITE_CLIENT_HANDLE);
    }

    /**
     * @param string[] $plugins
     */
    private function inject_react_refresh_preamble(string $origin, string $base, array $plugins): void
    {
        if ($this->reactRefreshPreamblePrinted) {
            return;
        }

        if (!in_array('vite:react-refresh', $plugins, true)) {
            return;
        }

        $refresh_src = $this->dev_asset_src($origin, $base, '@react-refresh');
        $position = 'after';
        $script = <<<EOS
import RefreshRuntime from "{$refresh_src}";
RefreshRuntime.injectIntoGlobalHook(window);
window.\$RefreshReg$ = () => {};
window.\$RefreshSig$ = () => (type) => type;
window.__vite_plugin_react_preamble_installed__ = true;
EOS;

        wp_add_inline_script(self::VITE_CLIENT_HANDLE, $script, $position);
        add_filter(
            'wp_inline_script_attributes',
            function (array $attributes) use ($position): array {
                if (isset($attributes['id']) && $attributes['id'] === self::VITE_CLIENT_HANDLE . "-js-{$position}") {
                    $attributes['type'] = 'module';
                }

                return $attributes;
            }
        );

        $this->reactRefreshPreamblePrinted = true;
    }

    private function dev_asset_src(string $origin, string $base, string $entry): string
    {
        $path = trim((string) preg_replace('#[/]{2,}#', '/', "{$base}/{$entry}"), '/');

        return sprintf('%s/%s', untrailingslashit($origin), $path);
    }

    /**
     * Add `type="module"` to the target handle's script tag via the `script_loader_tag` filter.
     */
    private function filter_script_tag(string $target_handle): void
    {
        add_filter(
            'script_loader_tag',
            function (string $tag, string $handle, string $src) use ($target_handle): string {
                if ($handle !== $target_handle) {
                    return $tag;
                }

                $processor = new WP_HTML_Tag_Processor($tag);
                $found = false;
                do {
                    $found = $processor->next_tag(['tag_name' => 'script']);
                } while ($processor->get_attribute('src') !== $src);

                if ($found) {
                    $processor->set_attribute('type', 'module');
                }

                return $processor->get_updated_html();
            },
            10,
            3
        );
    }

    /**
     * Resolve the public URL for the dist directory, mirroring upstream prepare_asset_url().
     */
    private function prepare_asset_url(string $dir): string
    {
        $content_dir = wp_normalize_path(WP_CONTENT_DIR);
        $manifest_dir = wp_normalize_path($dir);
        $url = content_url(str_replace($content_dir, '', $manifest_dir));

        $matches = preg_match(
            '/(?<address>http(?:s?):\/\/.*\/)(?<fullPath>wp-content(?<removablePath>\/.*)\/(?:plugins|themes)\/.*)/',
            $url,
            $parts
        );

        if ($matches === 0) {
            return $url;
        }

        return sprintf('%s%s', $parts['address'], str_replace($parts['removablePath'], '', $parts['fullPath']));
    }

    /**
     * @return mixed Decoded JSON (associative), or null when the file is unreadable/invalid.
     */
    private function read_json(string $file)
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        return json_decode($contents, true);
    }

    /**
     * Graceful failure: die only under WP_DEBUG, otherwise no-op with a false return.
     */
    private function fail(string $message): bool
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die(esc_html($message));
        }

        return false;
    }
}
