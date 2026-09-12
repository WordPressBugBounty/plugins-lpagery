<?php

namespace LPagery\multilingual;

/**
 * Polylang implementation of the {@see MultilingualPlugin} seam.
 *
 * This is the only place in LPagery that may name a Polylang symbol. Polylang stores a post's
 * Page Language as a term of the `language` taxonomy attached through `term_relationships`, but
 * nothing outside this class needs to know that: every read and write goes through Polylang's
 * public pll_* API, and the queries that need the language reach it through the SQL fragments
 * this adapter hands back.
 */
class PolylangAdapter implements MultilingualPlugin
{
    /** @var array<string, array{name: string, flag_url: string|null}>|null Memoized per request. */
    private ?array $languages = null;

    /** @var array<string, true> Terms already reported as untranslated, keyed taxonomy:term:language. */
    private array $reported_untranslated_terms = array();

    public function is_active(): bool
    {
        // Polylang loads its public API from its own bootstrap, so the presence of those functions
        // is the signal that the plugin is installed and active. LPagery only ever reaches the
        // adapter from admin, AJAX and render hooks, all of which run after Polylang has booted.
        return function_exists('pll_get_post_language') && function_exists('pll_set_post_language') && function_exists('pll_languages_list');
    }

    public function get_post_language(int $post_id): ?string
    {
        $language = pll_get_post_language($post_id, 'slug');
        return is_string($language) && $language !== '' ? $language : null;
    }

    public function set_post_language(int $post_id, string $language): void
    {
        // Re-asserting the language is deliberate: the Page Language is inherited from the
        // Template Page on every pass, so a hand-changed language is overwritten, not kept.
        pll_set_post_language($post_id, $language);
    }

    public function sync_post_terms_language(int $post_id, string $language, array $created_term_ids): void
    {
        // Terms LPagery created during this pass have no language of their own yet, so the Page
        // Language is simply forced onto them - unless Polylang does not translate their taxonomy,
        // where a language would only attach a relationship Polylang never expects.
        $created = array();
        foreach ($created_term_ids as $term_id) {
            $term_id = (int)$term_id;
            $created[$term_id] = true;
            $this->set_term_language($term_id, $language);
        }

        foreach (get_object_taxonomies((string)get_post_type($post_id)) as $taxonomy) {
            if (!pll_is_translated_taxonomy($taxonomy)) {
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
                // that belong to a different language, and never repairs Polylang's own data.
                $term_language = pll_get_term_language($term_id, 'slug');
                if (!is_string($term_language) || $term_language === '' || $term_language === $language) {
                    $replaced[] = $term_id;
                    continue;
                }

                // A pre-existing term of another language is exchanged for its translation in the
                // Page Language when Polylang already has one. LPagery never creates the
                // translation: an untranslated term stays attached and is reported once.
                $translation = pll_get_term($term_id, $language);
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
        // Polylang gives a brand-new term the site's default language. Left that way, attaching it
        // to a page of another language makes Polylang create a translated term of the same name,
        // so the Page Language has to be on the term before it reaches the page.
        $term = get_term($term_id);
        if (is_object($term) && isset($term->taxonomy) && !pll_is_translated_taxonomy($term->taxonomy)) {
            return;
        }
        pll_set_term_language($term_id, $language);
    }

    public function find_term(string $name, string $taxonomy, string $language): ?int
    {
        // Polylang narrows a term query to one language through its own `lang` argument. Without
        // it WordPress answers from the language the request runs in, so a term created for an
        // earlier row of the same Page Set stays invisible and a duplicate gets created.
        $term_ids = get_terms(array('taxonomy' => $taxonomy, 'name' => $name, 'lang' => $language,
            'hide_empty' => false, 'fields' => 'ids', 'number' => 1));
        if (!is_array($term_ids) || $term_ids === array()) {
            return null;
        }

        return (int)reset($term_ids);
    }

    public function get_translated_attachment(int $attachment_id, string $language): ?int
    {
        $translation = pll_get_post($attachment_id, $language);
        return $translation ? (int)$translation : null;
    }

    public function get_language_data(int $post_id): PostLanguageData
    {
        // Polylang filters get_permalink() by the post's own language, so the plain call already
        // yields the language-aware URL. get_permalink() answers false for a post that is gone;
        // normalise once so both branches hand back null rather than an empty string.
        $permalink = get_permalink($post_id) ?: null;
        $language_code = $this->get_post_language($post_id);
        if ($language_code === null) {
            return new PostLanguageData(null, $permalink);
        }

        $languages = $this->get_languages();
        $language = $languages[$language_code] ?? null;

        return new PostLanguageData($language_code, $permalink, $language['name'] ?? null,
            $language['flag_url'] ?? null);
    }

    public function get_current_admin_language(): ?string
    {
        $language = pll_current_language('slug');
        if (!is_string($language) || $language === '') {
            // Polylang only sets a current language once the admin picks one in its own language
            // filter, so without this fallback most admin requests would report no language at all.
            $language = pll_default_language('slug');
        }
        return is_string($language) && $language !== '' ? $language : null;
    }

    public function get_languages(): array
    {
        if ($this->languages !== null) {
            return $this->languages;
        }

        // 'fields' => '' asks Polylang for the language objects rather than the bare slugs, which
        // is the only shape carrying both the display name and the flag URL.
        $list = pll_languages_list(array('fields' => ''));
        $languages = array();
        if (is_array($list)) {
            foreach ($list as $language) {
                if (!is_object($language) || !isset($language->slug) || !is_string($language->slug) || $language->slug === '') {
                    continue;
                }
                $slug = $language->slug;
                $name = isset($language->name) && is_string($language->name) && $language->name !== '' ? $language->name : $slug;
                $languages[$slug] = array('name' => $name, 'flag_url' => $this->get_flag_url($language));
            }
        }

        $this->languages = $languages;
        return $languages;
    }

    public function post_language_sql(string $post_alias): LanguageSqlFragments
    {
        global $wpdb;
        $relationships = $wpdb->prefix . 'term_relationships';
        $taxonomy = $wpdb->prefix . 'term_taxonomy';
        $terms = $wpdb->prefix . 'terms';

        // The three language tables are joined to each other INSIDE one parenthesised nested join,
        // so a post that carries other terms (categories, tags) still contributes exactly one row:
        // the inner joins drop every non-language relationship before the outer LEFT JOIN matches.
        // Filtering the taxonomy in a plain chain of LEFT JOINs would multiply rows instead.
        // This assumes Polylang's own invariant of at most one `language` term per post; a post
        // carrying two would fan out, exactly as it does in Polylang's own queries.
        $join = "LEFT JOIN ($relationships lpagery_lang_rel" . " INNER JOIN $taxonomy lpagery_lang_tax ON lpagery_lang_tax.term_taxonomy_id = lpagery_lang_rel.term_taxonomy_id AND lpagery_lang_tax.taxonomy = 'language'" . " INNER JOIN $terms lpagery_lang ON lpagery_lang.term_id = lpagery_lang_tax.term_id)" . " ON lpagery_lang_rel.object_id = $post_alias.ID";

        return new LanguageSqlFragments('lpagery_lang.slug AS language_code', $join, 'lpagery_lang.slug = %s');
    }

    public function plugin_key(): string
    {
        return 'polylang';
    }

    /**
     * The flag Polylang itself displays for a language: a custom flag when the site uploaded one,
     * the stock flag otherwise, and null when Polylang has neither.
     *
     * @param object $language A Polylang language object.
     */
    private function get_flag_url(object $language): ?string
    {
        $flag_url = method_exists($language, 'get_display_flag_url')
            ? $language->get_display_flag_url()
            : ($language->flag_url ?? null);
        return is_string($flag_url) && $flag_url !== '' ? $flag_url : null;
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
        error_log('LPagery: term ' . $term_id . ' in taxonomy ' . $taxonomy . ' has no Polylang translation for language ' . $language . '; leaving it attached as it is.');
    }
}
