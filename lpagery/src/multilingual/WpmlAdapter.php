<?php

namespace LPagery\multilingual;

/**
 * WPML implementation of the {@see MultilingualPlugin} seam.
 *
 * Absorbs the former WpmlHandler and WpmlHelper: reading the Template Page's language and
 * re-asserting it on the Generated Page through WPML's element-language details, keeping the
 * terms of a Generated Page in its language, and answering the language questions the rest
 * of LPagery asks (translated attachment, language data for a payload, current admin language and
 * the SQL fragments that let a query carry or filter by language).
 *
 * This is the only place in LPagery that may name a WPML symbol.
 */
class WpmlAdapter implements MultilingualPlugin
{
    /** @var array<string, true> taxonomy:term_id:language keys already reported this request */
    private array $reported_untranslated_terms = array();

    public function is_active(): bool
    {
        return function_exists('wpml_get_language_information') && defined('ICL_SITEPRESS_VERSION') && $this->translations_table_exists();
    }

    public function get_post_language(int $post_id): ?string
    {
        $language_information = wpml_get_language_information(null, $post_id);
        if (!$language_information) {
            return null;
        }
        $language_details = apply_filters('wpml_element_language_details', null,
            array('element_id' => $post_id, 'element_type' => get_post_type($post_id)));
        if (!is_object($language_details) || empty($language_details->language_code)) {
            return null;
        }
        return $language_details->language_code;
    }

    public function set_post_language(int $post_id, string $language): void
    {
        /** @var \SitePress|null $sitepress */
        global $sitepress;
        if ($sitepress === null) {
            return;
        }
        $post_type = get_post_type($post_id);
        $wpml_post_type = 'post_' . $post_type;
        $trid = $sitepress->get_element_trid($post_id, $wpml_post_type);
        // Re-asserting the language is deliberate: the Page Language is inherited from the
        // Template Page on every pass, so a hand-changed language is overwritten, not kept.
        $sitepress->set_element_language_details($post_id, $wpml_post_type, $trid, $language);

        $this->clear_language_caches((string)$post_type, (int)$trid);
    }

    public function sync_post_terms_language(int $post_id, string $language, array $created_term_ids): void
    {
        /** @var \SitePress|null $sitepress */
        global $sitepress;
        if ($sitepress === null) {
            return;
        }

        // Terms LPagery created during this pass were born in the language of the admin request,
        // which is the site default rather than the Page Language, so the Page Language is forced
        // onto them. WPML's own sync is deliberately not used: it detaches a term whose language
        // differs from the post's, which loses the spreadsheet value instead of keeping it.
        $created = array();
        foreach ($created_term_ids as $term_id) {
            $term_id = (int)$term_id;
            $created[$term_id] = true;
            $this->set_term_language($term_id, $language);
        }

        foreach (get_object_taxonomies((string)get_post_type($post_id)) as $taxonomy) {
            if (!$sitepress->is_translated_taxonomy($taxonomy)) {
                continue;
            }
            $term_ids = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
            if (!is_array($term_ids) || $term_ids === array()) {
                continue;
            }

            $replaced = array();
            $changed = false;
            foreach ($term_ids as $term_id) {
                $term_id = (int)$term_id;
                if (isset($created[$term_id])) {
                    $replaced[] = $term_id;
                    continue;
                }

                // A term with no language of its own is left alone: LPagery only re-points terms
                // that belong to a different language, and never repairs WPML's own data.
                $term_language = $this->get_term_language($term_id, $taxonomy);
                if ($term_language === null || $term_language === $language) {
                    $replaced[] = $term_id;
                    continue;
                }

                // A pre-existing term of another language is exchanged for its translation in the
                // Page Language when WPML already has one. LPagery never creates the translation:
                // an untranslated term stays attached and is reported once.
                $translation = apply_filters('wpml_object_id', $term_id, $taxonomy, false, $language);
                if ($translation) {
                    $replaced[] = (int)$translation;
                    $changed = true;
                    continue;
                }

                $replaced[] = $term_id;
                $this->report_untranslated_term($term_id, $taxonomy, $language);
            }

            if ($changed) {
                wp_set_object_terms($post_id, $replaced, $taxonomy);
            }
        }
    }

    public function set_term_language(int $term_id, string $language): void
    {
        /** @var \SitePress|null $sitepress */
        global $sitepress;
        if ($sitepress === null) {
            return;
        }
        $term = get_term($term_id);
        if (!is_object($term) || !isset($term->taxonomy, $term->term_taxonomy_id)) {
            return;
        }
        // WPML keeps no language for a taxonomy it does not translate, so writing one would leave
        // a translations row nothing else expects.
        if (!$sitepress->is_translated_taxonomy($term->taxonomy)) {
            return;
        }
        // WPML keys a term by its term_taxonomy_id under a tax_<taxonomy> element type. A term
        // WPML saw being inserted already has a trid, which is kept so its translation group
        // survives; a term without one gets a fresh group from WPML.
        $element_type = 'tax_' . $term->taxonomy;
        $term_taxonomy_id = (int)$term->term_taxonomy_id;
        $trid = $sitepress->get_element_trid($term_taxonomy_id, $element_type);
        $sitepress->set_element_language_details($term_taxonomy_id, $element_type, $trid, $language);
    }

    public function find_term(string $name, string $taxonomy, string $language): ?int
    {
        // WordPress's own lookup by name ignores the language, so it may hand back a term of
        // another language while the one LPagery created in the Page Language a row earlier goes
        // unseen. The translations table, keyed by term_taxonomy_id, answers the exact question.
        global $wpdb;
        $prepare = $wpdb->prepare("SELECT t.term_id
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                INNER JOIN {$wpdb->prefix}icl_translations lpagery_lang
                    ON lpagery_lang.element_id = tt.term_taxonomy_id AND lpagery_lang.element_type = %s
                WHERE t.name = %s AND tt.taxonomy = %s AND lpagery_lang.language_code = %s
                ORDER BY t.term_id ASC
                LIMIT 1", 'tax_' . $taxonomy, $name, $taxonomy, $language);
        $term_id = $wpdb->get_var($prepare);

        return $term_id ? (int)$term_id : null;
    }

    public function get_translated_attachment(int $attachment_id, string $language): ?int
    {
        $type = apply_filters('wpml_element_type', get_post_type($attachment_id));
        $trid = apply_filters('wpml_element_trid', false, $attachment_id, $type);
        $translations = apply_filters('wpml_get_element_translations', array(), $trid, $type);
        if (!is_array($translations)) {
            return null;
        }

        foreach ($translations as $translation) {
            if (!isset($translation->element_id)) {
                continue;
            }
            $details = apply_filters('wpml_post_language_details', null, $translation->element_id);
            if (is_array($details) && isset($details['language_code']) && $details['language_code'] === $language) {
                return (int)$translation->element_id;
            }
        }
        return null;
    }

    public function get_language_data(int $post_id): PostLanguageData
    {
        // get_permalink() answers false for a post that is gone; normalise once so both branches
        // hand back null rather than an empty string.
        $permalink = get_permalink($post_id) ?: null;
        $language_information = wpml_get_language_information(null, $post_id);
        // A WP_Error (or false) is not an array, so the is_array guard covers every failure shape.
        if (!is_array($language_information) || empty($language_information['language_code'])) {
            return new PostLanguageData(null, $permalink);
        }

        $language_code = (string)$language_information['language_code'];
        $language_name = $language_information['native_name'] ?? ($language_information['display_name'] ?? null);

        return new PostLanguageData($language_code,
            apply_filters('wpml_permalink', $permalink, $language_code),
            $language_name === null ? null : (string)$language_name,
            $this->get_flag_url($language_code));
    }

    public function get_current_admin_language(): ?string
    {
        /** @var \SitePress|null $sitepress */
        global $sitepress;
        if ($sitepress === null) {
            return null;
        }
        $language = $sitepress->get_current_language();
        return $language ? (string)$language : null;
    }

    public function get_languages(): array
    {
        $active_languages = apply_filters('wpml_active_languages', null);
        if (!is_array($active_languages)) {
            return array();
        }

        $languages = array();
        foreach ($active_languages as $key => $language) {
            if (!is_array($language)) {
                continue;
            }
            $code = isset($language['code']) ? (string)$language['code'] : (string)$key;
            if ($code === '') {
                continue;
            }
            $name = $language['native_name'] ?? ($language['translated_name'] ?? $code);
            $name = is_string($name) && $name !== '' ? $name : $code;
            $flag_url = $language['country_flag_url'] ?? null;
            $languages[$code] = array(
                'name' => $name,
                'flag_url' => is_string($flag_url) && $flag_url !== '' ? $flag_url : null,
            );
        }

        return $languages;
    }

    public function post_language_sql(string $post_alias): LanguageSqlFragments
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'icl_translations';

        // The element type is derived in SQL (CONCAT) rather than bound, so the join carries no
        // placeholder of its own and callers keep a stable prepare() arity.
        return new LanguageSqlFragments('lpagery_lang.language_code AS language_code',
            "LEFT JOIN $table_name lpagery_lang ON lpagery_lang.element_id = $post_alias.ID AND lpagery_lang.element_type = CONCAT('post_', $post_alias.post_type)",
            'lpagery_lang.language_code = %s');
    }

    public function plugin_key(): string
    {
        return 'wpml';
    }

    /**
     * WPML's own flag URL for a language, or null on WPML builds that do not expose one — the
     * frontend then falls back to WPML's conventional flag path.
     */
    private function get_flag_url(string $language_code): ?string
    {
        /** @var \SitePress|null $sitepress */
        global $sitepress;
        if ($sitepress === null || !method_exists($sitepress, 'get_flag_url')) {
            return null;
        }
        $flag_url = $sitepress->get_flag_url($language_code);
        return $flag_url ? (string)$flag_url : null;
    }

    /** The WPML language of a term, or null when WPML holds none for it. */
    private function get_term_language(int $term_id, string $taxonomy): ?string
    {
        /** @var \SitePress|null $sitepress */
        global $sitepress;
        $term = get_term($term_id);
        if ($sitepress === null || !is_object($term) || !isset($term->term_taxonomy_id)) {
            return null;
        }
        $details = $sitepress->get_element_language_details((int)$term->term_taxonomy_id, 'tax_' . $taxonomy);
        if (!is_object($details) || empty($details->language_code)) {
            return null;
        }
        return (string)$details->language_code;
    }

    /**
     * Report a term LPagery had to leave in the wrong language, once per request.
     *
     * A Page Set runs this adapter over hundreds of pages that share the same terms, so logging per
     * page would bury the site's log under one identical line per Generated Page.
     */
    private function report_untranslated_term(int $term_id, string $taxonomy, string $language): void
    {
        $key = $taxonomy . ':' . $term_id . ':' . $language;
        if (isset($this->reported_untranslated_terms[$key])) {
            return;
        }
        $this->reported_untranslated_terms[$key] = true;
        error_log('LPagery: term ' . $term_id . ' in taxonomy ' . $taxonomy . ' has no WPML translation for language ' . $language . '; leaving it attached as it is.');
    }

    private function clear_language_caches(string $post_type, int $trid): void
    {
        if (defined('WPML_PLUGIN_PATH') && file_exists(WPML_PLUGIN_PATH . '/inc/cache.php')) {
            require_once WPML_PLUGIN_PATH . '/inc/cache.php';
        }
        if (function_exists('icl_cache_clear')) {
            icl_cache_clear($post_type . 's_per_language', true);
        }
        if (method_exists('\WPML\LIB\WP\Cache', 'clearMemoizedFunction')) {
            \WPML\LIB\WP\Cache::clearMemoizedFunction('get_source_language_by_trid', $trid);
        }
    }

    private function translations_table_exists(): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'icl_translations';
        $prepare = $wpdb->prepare("SELECT EXISTS (
                SELECT
                    TABLE_NAME
                FROM
                    information_schema.TABLES
                WHERE
                        TABLE_NAME = %s and TABLE_SCHEMA = %s
            ) as lpagery_table_exists;", $table_name, $wpdb->dbname);
        return (bool)$wpdb->get_var($prepare);
    }
}
