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


    // Other methods of your class

    public function lpagery_get_duplicated_slugs($data, $process_id, $slug, $template_id)
    {
        if(!$data) {
            return  ["filename_slug_equals" => [], "all_slugs_are_the_same" => true, "duplicates" => [], "existing_slugs" => [], "numeric_slugs" => [], "title_contains_placeholder" => false, "attachment_slug_equals" => []];
        }
        if (is_string($data)) {
            $json_decode = $this->substitutionDataPreparator->prepare_data($data);
        } else {
            $json_decode = $data;
        }
        if (!$slug) {
            $process_data = $this->lpageryDao->lpagery_get_process_by_id($process_id);
            $slug = maybe_unserialize($process_data->data)["slug"];
        }

        $slugs = $this->duplicateSlugHelper->get_slugs_from_json_input($slug, $json_decode);
        $post_type = get_post_type($template_id);
        $post = get_post($template_id);
        $title = $post->post_title;
        $title_contains_placeholder = $this->duplicateSlugHelper->check_post_title_contains_at_least_one_placeholder($title, $json_decode);
        $all_slugs_are_the_same = $this->duplicateSlugHelper->check_all_slugs_are_the_same($slugs);
        $filename_slug_equals = $this->duplicateSlugHelper->get_filenames_slug_equals($slug, $json_decode);
        if(!$title_contains_placeholder || $all_slugs_are_the_same) {
            return  ["filename_slug_equals" => [], "all_slugs_are_the_same" => $all_slugs_are_the_same, "duplicates" => [], "existing_slugs" => [], "numeric_slugs" => [], "title_contains_placeholder" => $title_contains_placeholder,  "attachment_slug_equals" => []];
        }
        $existing_slugs = $this->lpageryDao->lpagery_get_existing_posts_by_slug($slugs, $process_id, $post_type, $template_id);

        $duplicates = $this->duplicateSlugHelper->lpagery_find_array_duplicates($slugs);
        $numeric_slugs = $this->duplicateSlugHelper->lpagery_find_array_numeric_values($slugs);

        $attachment_slug_equals = $this->lpageryDao->lpagery_get_existing_attachments_by_slug($slugs);


        return ["filename_slug_equals" => $filename_slug_equals, "all_slugs_are_the_same" => $all_slugs_are_the_same, "duplicates" => $duplicates, "existing_slugs" => $existing_slugs, "numeric_slugs" => $numeric_slugs, "title_contains_placeholder" => $title_contains_placeholder, "attachment_slug_equals" => $attachment_slug_equals];
    }



}
