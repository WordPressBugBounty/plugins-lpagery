<?php

namespace LPagery\service\preflight;

use LPagery\service\preparation\InputParamProvider;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\service\substitution\SubstitutionHandler;

/**
 * Turns the request's source rows into {@see PreflightRow}s the way a run would see them: the slug
 * is substituted and sanitized by WordPress on the server, with the site language's handling of
 * accents and umlauts, and never taken from the browser. Rows skipped by `lpagery_ignore` are left
 * out, and every other row keeps its `row_id`.
 *
 * Nothing here touches the database per row: the `lpagery_parent` values are collected and
 * resolved together once.
 */
class PreflightRowResolver
{
    private SubstitutionDataPreparator $substitutionDataPreparator;
    private InputParamProvider $inputParamProvider;
    private SubstitutionHandler $substitutionHandler;
    private PreflightPostResolver $postResolver;

    public function __construct(SubstitutionDataPreparator $substitutionDataPreparator, InputParamProvider $inputParamProvider, SubstitutionHandler $substitutionHandler, PreflightPostResolver $postResolver)
    {
        $this->substitutionDataPreparator = $substitutionDataPreparator;
        $this->inputParamProvider = $inputParamProvider;
        $this->substitutionHandler = $substitutionHandler;
        $this->postResolver = $postResolver;
    }

    /**
     * @return PreflightRow[]
     */
    public function resolve(PreflightRequest $request, string $post_type): array
    {
        $resolved = [];
        $parent_terms = [];
        foreach ($request->rows as $row) {
            $data = $this->substitutionDataPreparator->recursive_sanitize_array([$row['data']])[0];
            if (array_key_exists('lpagery_ignore', $data) && filter_var($data['lpagery_ignore'], FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $params = $this->inputParamProvider->lpagery_get_input_params_without_images($data);
            $slug = $this->substitutionHandler->lpagery_substitute_slug($params, $request->slug);

            $parent_term = '';
            if (!empty($data['lpagery_parent'])) {
                $parent_term = (string)$this->substitutionHandler->lpagery_substitute($params, $data['lpagery_parent']);
                if ($parent_term !== '') {
                    $parent_terms[$parent_term] = true;
                }
            }
            $resolved[] = [$row['row_id'], sanitize_title(strip_tags((string)$slug)), $parent_term, $data];
        }

        $parents = $this->postResolver->resolve_parents(array_map('strval', array_keys($parent_terms)), $post_type);
        // A value that substitutes to nothing keeps the configured parent on purpose, like an empty cell.
        $parents_apply = !empty($parent_terms) && is_post_type_hierarchical($post_type);

        return array_map(function ($row) use ($parents, $request, $parents_apply) {
            $unknown = $parents_apply && $row[2] !== '' && !isset($parents[$row[2]]) ? $row[2] : null;
            return new PreflightRow((int)$row[0], $row[1], $row[2] !== '' && isset($parents[$row[2]]) ? $parents[$row[2]] : $request->parent_id, $row[3], $unknown);
        }, $resolved);
    }
}
