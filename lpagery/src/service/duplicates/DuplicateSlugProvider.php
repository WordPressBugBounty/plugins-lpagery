<?php

namespace LPagery\service\duplicates;


use LPagery\data\LPageryDao;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\utils\Utils;


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
            self::$instance = new self($substitutionDataPreparator, $lpageryDao, $duplicateSlugHelper);
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

                if (strtolower($placeholder) === strtolower(Utils::lpagery_sanitize_title_with_dashes($key))) {
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

    public function lpagery_get_duplicated_slugs($data, int $post_id, bool $includeParentAsIdentifier, ?int $parent_id, ?string $slug = null, ?int $process_id = 0, array $keys = [], ?bool $early_abort = true): DuplicateSlugResult
    {
        if (!$data) {
            return new DuplicateSlugResult(true, [], [], [], false, [], false);
        }

        if (is_string($data)) {
            $json_decode = $this->substitutionDataPreparator->prepare_data($data);
        } else {
            $json_decode = $this->substitutionDataPreparator->recursive_sanitize_array($data);
        }
        if (!$slug) {
            $process = $this->lpageryDao->lpagery_get_process_by_id($process_id);
            $process_data = maybe_unserialize($process->data);
            $slug = $process_data['slug'];
        }

        $missing_placeholders = $this->findMissingPlaceholders($slug, $keys);
        $slugs_result = $this->duplicateSlugHelper->get_slugs_from_json_input($slug, $json_decode,
            get_post_type($post_id), $parent_id);

        $slugs = array_map(function ($element) {
            return $element->slug;
        }, $slugs_result);


        $post = get_post($post_id);

        $title_contains_placeholder = $this->duplicateSlugHelper->check_post_title_contains_at_least_one_placeholder($post->post_title,
            $json_decode);
        $slug_contains_placeholder = $this->duplicateSlugHelper->check_slug_contains_at_least_one_placeholder($slug,
            $json_decode);


        if ($early_abort && (!empty($missing_placeholders) || !$slug_contains_placeholder || !$title_contains_placeholder)) {
            return new DuplicateSlugResult($slug_contains_placeholder, [], [], [], $title_contains_placeholder, [],
                false, $missing_placeholders);
        }

        $post_type = get_post_type($post_id);

        $existing_slugs = $this->lpageryDao->lpagery_get_existing_posts_by_slug($slugs_result, $process_id, $post_type,
            $post_id);
        $duplicates = $this->duplicateSlugHelper->lpagery_find_array_duplicates($slugs_result,
            $includeParentAsIdentifier);
        $attachment_slugs = $this->lpageryDao->lpagery_get_existing_attachments_by_slug($slugs);
        $numeric_slugs = $this->duplicateSlugHelper->lpagery_find_array_numeric_values($slugs_result);

        $foundDuplicatedSlugsWithDifferentParents = false;
        if (!$includeParentAsIdentifier) {
            $foundDuplicatedSlugsWithDifferentParents = $this->duplicateSlugHelper->lpagery_find_duplicated_slugs_with_different_parents($slugs_result);
        }

        return new DuplicateSlugResult($slug_contains_placeholder, $duplicates, $existing_slugs, $numeric_slugs,
            $title_contains_placeholder, $attachment_slugs, $foundDuplicatedSlugsWithDifferentParents,
            $missing_placeholders);
    }
}
