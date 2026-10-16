<?php

namespace LPagery\service\lookup;

use LPagery\service\preflight\PreflightPostResolver;
use LPagery\service\preflight\PreflightUserResolver;

/**
 * The read-only lookups behind the App Connection's content-sheet pickers: the author search and
 * the batch resolve of one column's values. Values match the way a sync matches them, through the
 * Pre-flight Check's set-based resolvers, so a value reported as found is a value the sync finds.
 */
class LookupService
{
    public const MAX_RESOLVE_VALUES = 500;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    private PreflightUserResolver $userResolver;
    private PreflightPostResolver $postResolver;
    private TermLookup $termLookup;
    private MediaLookup $mediaLookup;

    public function __construct(PreflightUserResolver $userResolver, PreflightPostResolver $postResolver, TermLookup $termLookup, MediaLookup $mediaLookup)
    {
        $this->userResolver = $userResolver;
        $this->postResolver = $postResolver;
        $this->termLookup = $termLookup;
        $this->mediaLookup = $mediaLookup;
    }

    /**
     * The users who can author the post type: those holding a role that grants the post type's
     * edit capability, the capability WordPress checks before letting a user author it. Only id,
     * display name and email leave the site.
     *
     * @param array<string, mixed> $context `post_type`, or `template_id` to derive it from the Template Page
     * @param string $search matched against display name, login and email
     * @return array{items: array<int, array{id: int, display_name: string, email: string}>, has_more: bool}
     * @throws \InvalidArgumentException when the context names no known post type
     */
    public function search_users(array $context, string $search, int $page, int $per_page): array
    {
        global $wpdb;
        $edit_cap = get_post_type_object($this->post_type_from_context($context))->cap->edit_posts;
        $roles = [];
        foreach (wp_roles()->roles as $role => $details) {
            if (!empty($details['capabilities'][$edit_cap])) {
                $roles[] = $role;
            }
        }
        if (empty($roles)) {
            return ['items' => [], 'has_more' => false];
        }

        // A user's roles are a serialized role => true map, so each role is matched as a quoted key,
        // the way WP_User_Query matches its `role__in`.
        $role_conditions = implode(' OR ', array_fill(0, count($roles), 'um.meta_value LIKE %s'));
        $args = [$wpdb->get_blog_prefix() . 'capabilities'];
        foreach ($roles as $role) {
            $args[] = '%' . $wpdb->esc_like('"' . $role . '"') . '%';
        }
        $search_condition = '';
        if ($search !== '') {
            $search_condition = 'AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($args, $like, $like, $like);
        }
        // One row past the page tells whether another page follows.
        array_push($args, $per_page + 1, ($page - 1) * $per_page);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.display_name, u.user_email FROM $wpdb->users u
             WHERE EXISTS (SELECT 1 FROM $wpdb->usermeta um
                           WHERE um.user_id = u.ID AND um.meta_key = %s AND ($role_conditions))
             $search_condition
             ORDER BY u.display_name ASC, u.ID ASC
             LIMIT %d OFFSET %d",
            $args
        )) ?: [];

        $items = array_map(function ($row) {
            return ['id' => (int)$row->ID, 'display_name' => (string)$row->display_name, 'email' => (string)$row->user_email];
        }, array_slice($rows, 0, $per_page));
        return ['items' => $items, 'has_more' => count($rows) > $per_page];
    }

    /**
     * The term picker: the terms of one taxonomy, each with its `Parent>Child` path, searched by
     * name and paged.
     *
     * @param array<string, mixed> $context `taxonomy`, and optionally the Page Language as `language`
     * @return array{items: array<int, array{id: int, name: string, path: string}>, has_more: bool}
     * @throws \InvalidArgumentException when the context names no known taxonomy
     */
    public function search_terms(array $context, string $search, int $page, int $per_page): array
    {
        return $this->termLookup->search($this->taxonomy_from_context($context), $search, $page, $per_page, (string)($context['language'] ?? ''));
    }

    /**
     * The media picker: the media library's images, searched by title and file name and paged.
     *
     * @return array{items: array<int, array{id: int, basename: string, title: string, thumbnail_url: string}>, has_more: bool}
     */
    public function search_media(string $search, int $page, int $per_page): array
    {
        return $this->mediaLookup->search($search, $page, $per_page);
    }

    /**
     * Answers each non-empty value with whether the site has what it names. Every non-empty value
     * appears as a key exactly as sent; the results are an object so a list of numeric values still
     * encodes as a JSON object.
     *
     * @param string $kind what the values name
     * @param string[] $values
     * @param array<string, mixed> $context kind-specific context
     * @return array{results: object}
     * @throws \InvalidArgumentException for an oversized batch or an unknown kind
     */
    public function resolve_values(string $kind, array $values, array $context): array
    {
        $values = array_values(array_unique(array_filter(array_map('strval', $values), function ($value) {
            return trim($value) !== '';
        })));
        if (count($values) > self::MAX_RESOLVE_VALUES) {
            throw new \InvalidArgumentException(sprintf('At most %d values can be resolved in one request.', self::MAX_RESOLVE_VALUES));
        }
        switch ($kind) {
            case 'user':
                $found = $this->resolve_users($values);
                break;
            case 'template':
                $found = $this->with_permalinks($this->postResolver->resolve_posts($values, $this->post_type_from_context($context)));
                break;
            case 'parent':
                $found = $this->with_permalinks($this->postResolver->resolve_parent_posts($values, $this->post_type_from_context($context)));
                break;
            case 'term':
                $found = $this->termLookup->resolve($this->taxonomy_from_context($context), $values, (string)($context['language'] ?? ''));
                break;
            case 'attachment':
                $found = $this->mediaLookup->resolve($values);
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Unknown kind "%s".', $kind));
        }
        $results = [];
        foreach ($values as $value) {
            $results[$value] = $found[$value] ?? ['found' => false];
        }
        return ['results' => (object)$results];
    }

    /**
     * The post type a lookup runs against: `post_type` as given, else the Template Page's.
     *
     * @param array<string, mixed> $context
     * @throws \InvalidArgumentException when the context names no known post type
     */
    private function post_type_from_context(array $context): string
    {
        $post_type = (string)($context['post_type'] ?? '');
        if ($post_type === '' && !empty($context['template_id'])) {
            $template = get_post((int)$context['template_id']);
            $post_type = $template ? (string)$template->post_type : '';
        }
        if ($post_type === '' || !get_post_type_object($post_type)) {
            throw new \InvalidArgumentException('A known post_type or template_id is required.');
        }
        return $post_type;
    }

    /**
     * The taxonomy a term lookup runs against.
     *
     * @param array<string, mixed> $context
     * @return \WP_Taxonomy|object
     * @throws \InvalidArgumentException when the context names no known taxonomy
     */
    private function taxonomy_from_context(array $context)
    {
        $taxonomy = (string)($context['taxonomy'] ?? '');
        $taxonomy_object = $taxonomy !== '' ? get_taxonomy($taxonomy) : false;
        if (!$taxonomy_object) {
            throw new \InvalidArgumentException('A known taxonomy is required.');
        }
        return $taxonomy_object;
    }

    /**
     * @param string[] $values
     * @return array<string, array{found: true, id: int, label: string}>
     */
    private function resolve_users(array $values): array
    {
        return array_map(function ($user) {
            return ['found' => true, 'id' => $user['id'], 'label' => $user['display_name']];
        }, $this->userResolver->resolve_users($values));
    }

    /**
     * @param array<string, array{id: int, title: string}> $posts
     * @return array<string, array{found: true, id: int, label: string, permalink: string}>
     */
    private function with_permalinks(array $posts): array
    {
        return array_map(function ($post) {
            return ['found' => true, 'id' => $post['id'], 'label' => $post['title'], 'permalink' => (string)get_permalink($post['id'])];
        }, $posts);
    }
}
