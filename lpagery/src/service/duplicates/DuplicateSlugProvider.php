<?php

namespace LPagery\service\duplicates;


use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\data\LPageryDao;


class DuplicateSlugProvider
{
    private static ?DuplicateSlugProvider $instance = null;
    private SubstitutionDataPreparator $substitutionDataPreparator;
    private LPageryDao $lpageryDao;
    private DuplicateSlugHelper $duplicateSlugHelper;

    private function __construct(SubstitutionDataPreparator $substitutionDataPreparator, LPageryDao $lpageryDao, DuplicateSlugHelper $duplicateSlugHelper)
    {
        $this->substitutionDataPreparator = $substitutionDataPreparator;
        $this->lpageryDao = $lpageryDao;
        $this->duplicateSlugHelper = $duplicateSlugHelper;
    }

    public static function get_instance(SubstitutionDataPreparator $substitutionDataPreparator, LPageryDao $lpageryDao, DuplicateSlugHelper $duplicateSlugHelper)
    {
        if (null === self::$instance) {
            self::$instance = new self(
                $substitutionDataPreparator,
                $lpageryDao,
                $duplicateSlugHelper
            );
        }
        return self::$instance;
    }

    private function findMissingPlaceholders(string $slug, array $keys): array
    {
        preg_match_all('/{([^}]+)}/', $slug, $matches);
        $placeholders = $matches[1];
        $missing = [];

        foreach ($placeholders as $placeholder) {
            $found = false;
            foreach ($keys as $key) {
                if (strtolower($placeholder) === strtolower($key)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $placeholder;
            }
        }

        return $missing;
    }

    public function lpagery_get_duplicated_slugs($data, int $post_id, ?string $slug = null, int $process_id = 0, array $keys = []): DuplicateSlugResult
    {
        if (!$data) {
            return new DuplicateSlugResult(true, [], [], [], false, [], []);
        }

        if (is_string($data)) {
            $json_decode = $this->substitutionDataPreparator->prepare_data($data);
        } else {
            $json_decode = $data;
        }
        if (!$slug) {
            $process = $this->lpageryDao->lpagery_get_process_by_id($process_id);
            $process_data = maybe_unserialize($process->data);
            $slug = $process_data['slug'];
        }

        $missing_placeholders = $this->findMissingPlaceholders($slug, $keys);
        $slugs = $this->duplicateSlugHelper->get_slugs_from_json_input($slug, $json_decode);

        $all_slugs_are_the_same = $this->duplicateSlugHelper->check_all_slugs_are_the_same($slugs);
        $post = get_post($post_id);

        $title_contains_placeholder = $this->duplicateSlugHelper->check_post_title_contains_at_least_one_placeholder($post->post_title, $json_decode);

        if (!empty($missing_placeholders) || $all_slugs_are_the_same || !$title_contains_placeholder) {
            return new DuplicateSlugResult($all_slugs_are_the_same, [], [], [], $title_contains_placeholder, [], $missing_placeholders);
        }

        $post_type = get_post_type($post_id);

        $existing_slugs = $this->lpageryDao->lpagery_get_existing_posts_by_slug($slugs, $post_id, $post_type, $process_id);
        $duplicates = $this->duplicateSlugHelper->lpagery_find_array_duplicates($slugs);
        $attachment_slugs = $this->lpageryDao->lpagery_get_existing_attachments_by_slug($slugs);
        $numeric_slugs = $this->duplicateSlugHelper->lpagery_find_array_numeric_values($slugs);

        return new DuplicateSlugResult(
            false,
            $duplicates,
            $existing_slugs,
            $numeric_slugs,
            $title_contains_placeholder,
            $attachment_slugs,
            []
        );
    }
}
