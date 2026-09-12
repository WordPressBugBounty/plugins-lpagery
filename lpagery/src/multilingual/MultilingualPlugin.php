<?php

namespace LPagery\multilingual;

/**
 * Seam for the active Multilingual Plugin (WPML or Polylang; at most one at a time).
 *
 * Nothing outside this namespace may call a WPML or Polylang function, read $sitepress, or
 * reference icl_translations / the Polylang "language" taxonomy. Consumers receive the resolved
 * adapter (or null when no Multilingual Plugin is active) through their constructor from the
 * CompositionRoot; they never resolve it themselves.
 *
 * @see MultilingualPluginResolver
 */
interface MultilingualPlugin
{
    /** True when this plugin is installed and usable in the current request. */
    public function is_active(): bool;

    /** The plugin's Page Language for a post, or null when it has none. */
    public function get_post_language(int $post_id): ?string;

    /** Force a post's Page Language, overwriting whatever it carried before. */
    public function set_post_language(int $post_id, string $language): void;

    /**
     * Bring the post's attached terms in line with its Page Language.
     *
     * Terms created during this pass are forced to $language; pre-existing terms of another
     * language are swapped for their translation when one exists. Never creates a translation.
     *
     * @param int[] $created_term_ids Ids of the terms created during this pass.
     */
    public function sync_post_terms_language(int $post_id, string $language, array $created_term_ids): void;

    /**
     * Give a term the Page Language, so it is not left in whatever language the plugin defaults to.
     *
     * Called right after LPagery creates a term, before the term is attached to the page: a
     * Multilingual Plugin may answer a page of one language carrying a term of another by creating
     * a translation of that term, which is exactly the duplicate this prevents.
     */
    public function set_term_language(int $term_id, string $language): void;

    /**
     * The id of the term named $name in $taxonomy that belongs to $language, or null when the
     * plugin cannot answer language-scoped term lookups.
     *
     * WordPress looks a term up by name alone, but a Multilingual Plugin may scope that lookup by
     * the language the request happens to run in, so a term LPagery created a moment ago in the
     * Page Language can be invisible on the next row. Callers ask here first and fall back to the
     * plain WordPress lookup when this answers null.
     */
    public function find_term(string $name, string $taxonomy, string $language): ?int;

    /** The attachment's translation in $language, or null to fall back to the source attachment. */
    public function get_translated_attachment(int $attachment_id, string $language): ?int;

    /** Language code, language-aware permalink, display name and flag URL for a post. */
    public function get_language_data(int $post_id): PostLanguageData;

    /** The language the admin is currently browsing in, or null when it cannot be determined. */
    public function get_current_admin_language(): ?string;

    /**
     * Every configured language, keyed by language code.
     *
     * Meant to be read once per admin page load, so the frontend can resolve a display name and a
     * flag from a row's language code without a per-row call into the Multilingual Plugin.
     *
     * @return array<string, array{name: string, flag_url: string|null}>
     */
    public function get_languages(): array;

    /** SQL fragments joining the plugin's language storage to a posts table aliased $post_alias. */
    public function post_language_sql(string $post_alias): LanguageSqlFragments;

    /** Stable key for this plugin: 'wpml' or 'polylang'. */
    public function plugin_key(): string;
}
