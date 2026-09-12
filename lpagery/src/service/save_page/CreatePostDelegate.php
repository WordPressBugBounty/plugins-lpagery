<?php

namespace LPagery\service\save_page;

use Exception;
use LPagery\data\repository\GeneratedPageRepository;
use LPagery\data\repository\PageSetRepository;
use LPagery\model\GenerationRequest;
use LPagery\model\PageCreationDashboardSettings;
use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\save_page\update\PageUpdateDataProvider;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\utils\Utils;
class CreatePostDelegate {
    private InputParamProvider $inputParamProvider;

    private SubstitutionHandler $substitutionHandler;

    private DynamicPageAttributeHandler $dynamicPageAttributeHandler;

    private PageSaver $pageSaver;

    private ?PageUpdateDataProvider $pageUpdateDataHandler;

    private SubstitutionDataPreparator $substitutionDataPreparator;

    private GeneratedPageRepository $generatedPageRepository;

    private PageSetRepository $pageSetRepository;

    public function __construct(
        InputParamProvider $inputParamProvider,
        SubstitutionHandler $substitutionHandler,
        DynamicPageAttributeHandler $dynamicPageAttributeHandler,
        PageSaver $pageSaver,
        ?PageUpdateDataProvider $pageUpdateDataHandler,
        SubstitutionDataPreparator $substitutionDataPreparator,
        GeneratedPageRepository $generatedPageRepository,
        PageSetRepository $pageSetRepository
    ) {
        $this->inputParamProvider = $inputParamProvider;
        $this->substitutionHandler = $substitutionHandler;
        $this->dynamicPageAttributeHandler = $dynamicPageAttributeHandler;
        $this->pageSaver = $pageSaver;
        $this->pageUpdateDataHandler = $pageUpdateDataHandler;
        $this->substitutionDataPreparator = $substitutionDataPreparator;
        $this->generatedPageRepository = $generatedPageRepository;
        $this->pageSetRepository = $pageSetRepository;
    }

    /**
     * @throws Exception
     */
    public function lpagery_create_post( GenerationRequest $request, array $processed_slugs ) : SavePageResult {
        if ( !defined( 'DOING_LPAGERY_CREATION' ) ) {
            define( 'DOING_LPAGERY_CREATION', true );
        }
        $process_id = $request->process_id;
        $page_id_to_be_updated = $request->page_id_to_be_updated;
        $client_generated_slug = $request->client_generated_slug;
        if ( $process_id <= 0 && !$page_id_to_be_updated ) {
            throw new Exception("Process ID must be set. This might be an issue with your Database-Version. Please check and consider updating the Database-Version");
        }
        if ( $process_id ) {
            $process_by_id = $this->pageSetRepository->get_process_by_id( $process_id );
            $templatePath = $process_by_id->post_id;
        } else {
            $templatePath = $request->update_template_id;
            $process_by_id = $this->pageSetRepository->get_process_by_created_post_id( $page_id_to_be_updated );
            $process_id = $process_by_id->id;
        }
        if ( $process_id <= 0 ) {
            throw new Exception("Process ID must be not found");
        }
        $template_post = get_post( $templatePath );
        if ( !$template_post ) {
            throw new Exception("Post with ID " . $templatePath . " not found");
        }
        $force_update_content = $request->force_update_content;
        $overwrite_manual_changes = $request->overwrite_manual_changes;
        $existing_page_update_action = $request->existing_page_update_action;
        $data = $request->data;
        if ( $request->page_id_to_be_updated !== null && !$data ) {
            $process_post_data = $this->generatedPageRepository->get_process_post_data( $request->page_id_to_be_updated );
            $data = maybe_unserialize( $process_post_data->data );
        }
        if ( is_string( $data ) ) {
            $json_decode = $this->substitutionDataPreparator->prepare_data( $data );
        } else {
            $json_decode = $data;
        }
        $taxonomy_terms = array();
        $status_from_process = 'publish';
        $status_from_dashboard = $request->status;
        $slug = Utils::lpagery_sanitize_title_with_dashes( $template_post->post_title );
        $parent_path = 0;
        $datetime = null;
        // Config blobs can miss keys or carry null (parent_path is nullable in the dashboard
        // schema, and conversion feeds stored configs back through this path).
        $process_config = $this->read_process_config( $process_by_id );
        if ( $process_config !== null ) {
            $status_from_process = $process_config["status"] ?? $status_from_process;
            $parent_path = $process_config["parent_path"] ?? 0;
            $slug = $process_config["slug"] ?? $slug;
            $taxonomy_terms = $this->get_taxonomy_terms( $process_config );
            if ( $request->publish_timestamp !== null ) {
                $datetime = iso8601_to_datetime( $request->publish_timestamp );
            }
        }
        $hashed_payload = $request->hashed_payload;
        $pageCreationSettings = new PageCreationDashboardSettings();
        $pageCreationSettings->parent = $parent_path;
        $pageCreationSettings->taxonomy_terms = $taxonomy_terms;
        $pageCreationSettings->slug = $slug;
        $pageCreationSettings->status_from_process = $status_from_process;
        $pageCreationSettings->publish_datetime = $datetime;
        $pageCreationSettings->status_from_dashboard = $status_from_dashboard;
        $pageCreationSettings->client_generated_slug = $client_generated_slug;
        $pageCreationSettings->hashed_payload = $hashed_payload;
        $include_parent_as_identifier = filter_var( $process_by_id->include_parent_as_identifier, FILTER_VALIDATE_BOOLEAN );
        // Render Mode gates on Extended (ADR 0009: the gate lives at CREATION only). A live set
        // requested without Extended silently degrades to classic generation. Resolved up front so the
        // media pass (ADR 0013 skip-copy) sees it — image params are provided inside the call below.
        $render_mode = $this->resolve_render_mode( $process_by_id, $request );
        $params = $this->inputParamProvider->lpagery_provide_input_params(
            $json_decode,
            $process_id,
            $template_post->ID,
            $pageCreationSettings,
            $force_update_content,
            $overwrite_manual_changes,
            $include_parent_as_identifier,
            $existing_page_update_action,
            $render_mode
        );
        // Materialize (ADR 0019) runs on every tier and names the page it rewrites up front, so it
        // needs no Extended update resolution: the caller resolved the page from its own bookkeeping
        // row before asking. Without this branch a free build, where the Extended block below is
        // removed entirely, would fall through to the create branch and refuse the very page it was
        // asked to materialize.
        if ( $request->is_materialization && $request->page_id_to_be_updated !== null ) {
            $page_to_materialize = get_post( $request->page_id_to_be_updated );
            if ( !$page_to_materialize instanceof \WP_Post ) {
                return new SavePageResult(
                    "ignored",
                    "page_not_found",
                    "",
                    null
                );
            }
            // Spin Seed (ADR 0017): the page keeps the spintax picks it was created with.
            $params->spin_seed = $this->generatedPageRepository->get_spin_seed( $process_id, (int) $page_to_materialize->ID );
            $postSaveHelper = new PostFieldProvider(
                $template_post,
                $params,
                $this->substitutionHandler,
                $page_to_materialize,
                $this->dynamicPageAttributeHandler
            );
            return $this->pageSaver->savePage(
                $template_post,
                $params,
                $postSaveHelper,
                $processed_slugs,
                $page_to_materialize
            );
        }
        if ( $request->allow_create ) {
            // Spin Seed (ADR 0017): a brand-new page draws its own seed here, before the field
            // provider is built, so every substituted surface of the page (content, title, excerpt,
            // meta, taxonomies, alt text) resolves its spintax from the same value. The repository
            // stores it with the row on insert and never rewrites it.
            $params->spin_seed = $this->draw_spin_seed();
            $postSaveHelper = new PostFieldProvider(
                $template_post,
                $params,
                $this->substitutionHandler,
                null,
                $this->dynamicPageAttributeHandler
            );
            return $this->pageSaver->savePage(
                $template_post,
                $params,
                $postSaveHelper,
                $processed_slugs,
                null
            );
        }
        // The slug is only known through the Extended update resolution, which a free build does not
        // have — the run is refused either way, with or without a slug to name.
        $slugToBeUpdated = ( $this->pageUpdateDataHandler !== null ? $this->pageUpdateDataHandler->getSlugToBeUpdated( $json_decode, $process_id ) : "" );
        return new SavePageResult(
            "ignored",
            "unknown_operation",
            $slugToBeUpdated,
            null
        );
    }

    /**
     * A fresh Spin Seed for a page being created: a positive integer in the 31-bit range, from a
     * CSPRNG-backed draw (ADR 0017). The range keeps it storable in the `spin_seed` INT column and
     * printable as a decimal string for the per-block hash.
     */
    private function draw_spin_seed() : int {
        return random_int( 1, 2147483647 );
    }

    private function resolve_render_mode( $process_by_id, GenerationRequest $request ) : string {
        // A per-call override wins over the set's configured type. Per-page Convert/Materialize
        // (ADR 0009, Phase 7) uses it to materialize a single page to classic without touching the
        // set config — degrading to classic is always safe, so it is honoured regardless of tier.
        $override = $request->generation_type_override;
        if ( $override === 'classic' ) {
            return 'classic';
        }
        // Written out of non-suffixed calls on purpose: Freemius removes a whole `if` whose condition
        // is a `__premium_only` call, so the negated suffixed form would take this classic fallback with
        // it and a free build would happily generate Live pages. `is_premium()` has to be part of it —
        // `is_plan_or_trial()` alone is true on a free build whose site already carries a paid plan.
        if ( !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial( 'extended' )) ) {
            return 'classic';
        }
        if ( $override === 'live' ) {
            return 'live';
        }
        $process_config = maybe_unserialize( $process_by_id->data ?? null );
        if ( is_array( $process_config ) && ($process_config['render_mode'] ?? null) === 'live' ) {
            return 'live';
        }
        return 'classic';
    }

    /**
     * The stored Page Set config (status, parent, slug pattern, taxonomy terms, publish date), or
     * null below Standard, where the defaults above stand.
     *
     * The tier gate sits here rather than around the block that reads the config, and is written out
     * of non-suffixed calls: a `__premium_only` guard is removed whole from the free build, which would
     * take the only call site of {@see self::get_taxonomy_terms()} with it. `is_premium()` is part of
     * the condition because `is_plan_or_trial()` alone is true on a free build whose site already
     * carries a paid plan.
     *
     * @return array<string, mixed>|null
     */
    private function read_process_config( $process_by_id ) : ?array {
        if ( !(lpagery_fs()->is_premium() && lpagery_fs()->is_plan_or_trial( 'standard' )) ) {
            return null;
        }
        $process_config = maybe_unserialize( $process_by_id->data );
        return ( is_array( $process_config ) ? $process_config : array() );
    }

    private function get_taxonomy_terms( $process_config ) {
        if ( !array_key_exists( 'taxonomy_terms', $process_config ) ) {
            return [
                "category" => $process_config["categories"] ?? [],
                "post_tag" => $process_config["tags"] ?? [],
            ];
        }
        return $process_config["taxonomy_terms"];
    }

}
