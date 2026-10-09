<?php

namespace LPagery\service\preflight\check;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;

/**
 * Rows whose page already exists on the site with the same slug and parent: either a page LPagery
 * doesn't manage, or one of another Page Set. The Page Set's own pages never count, so an Update
 * run doesn't report the pages it is about to update.
 *
 * Two set-based queries whatever the row count: the slugs in chunks, then the matched ids' Page
 * Set membership.
 */
class ExistingPagesCheck implements PreflightCheck
{
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(GeneratedPageRepository $generatedPageRepository)
    {
        $this->generatedPageRepository = $generatedPageRepository;
    }

    public function id(): string
    {
        return 'existing-pages';
    }

    public function run(PreflightContext $context): array
    {
        $request = $context->request();
        $row_ids_by_page = [];
        $slugs = [];
        foreach ($context->rows() as $row) {
            // WordPress gives a page without a slug one of its own, so an empty slug clashes with nothing.
            if ($row->slug === '') {
                continue;
            }
            $row_ids_by_page[$row->slug . '|' . $row->parent_id][] = $row->row_id;
            $slugs[$row->slug] = true;
        }
        if (empty($row_ids_by_page)) {
            return [];
        }
        $slugs = array_map('strval', array_keys($slugs));
        $posts = $this->generatedPageRepository->find_existing_posts_by_slugs($slugs, $context->post_type(),
            $request->process_id, $request->template_id);

        $matched = [];
        foreach ($posts as $post) {
            $key = $post->post_name . '|' . (int)$post->post_parent;
            if (isset($row_ids_by_page[$key])) {
                $matched[(int)$post->id] = ['slug' => (string)$post->post_name, 'row_ids' => $row_ids_by_page[$key]];
            }
        }
        if (empty($matched)) {
            return [];
        }

        $in_other_sets = array_flip($this->generatedPageRepository->find_post_ids_in_other_page_sets(array_keys($matched),
            $request->process_id));
        $not_managed = array_diff_key($matched, $in_other_sets);
        $managed = array_intersect_key($matched, $in_other_sets);

        return array_values(array_filter([
            $this->finding('existing-pages', $not_managed),
            $this->finding('pages-in-other-sets', $managed),
        ]));
    }

    /**
     * @param array<int, array{slug: string, row_ids: int[]}> $pages post id => the page and its rows
     */
    private function finding(string $kind, array $pages): ?Finding
    {
        if (empty($pages)) {
            return null;
        }
        $row_ids = array_merge(...array_values(array_column($pages, 'row_ids')));
        sort($row_ids);

        $examples = [];
        foreach (array_slice($pages, 0, Finding::MAX_EXAMPLES, true) as $post_id => $page) {
            $examples[] = ['slug' => $page['slug'], 'permalink' => (string)get_permalink($post_id), 'row_ids' => $page['row_ids']];
        }
        return Finding::rows(Finding::NOTE, $kind, $row_ids, $examples);
    }
}
