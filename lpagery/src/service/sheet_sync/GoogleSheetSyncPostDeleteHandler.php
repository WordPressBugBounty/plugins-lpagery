<?php

namespace LPagery\service\sheet_sync;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\data\LPageryDao;

class GoogleSheetSyncPostDeleteHandler
{

    private static $instance;
    private LPageryDao $lpageryDao;
    private InputParamProvider $inputParamProvider;
    private SubstitutionHandler $substitutionHandler;

    public function __construct(LPageryDao $LPageryDao, InputParamProvider $inputParamProvider,  SubstitutionHandler $substitutionHandler)
    {
        $this->lpageryDao = $LPageryDao;
        $this->inputParamProvider = $inputParamProvider;
        $this->substitutionHandler = $substitutionHandler;
    }

    public static function get_instance(LPageryDao $LPageryDao, InputParamProvider $inputParamProvider,  SubstitutionHandler $substitutionHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self( $LPageryDao, $inputParamProvider, $substitutionHandler);
        }
        return self::$instance;
    }


    public function handleDeletions($input_data, $process)
    {
        $slugs_from_sheet = [];

        set_time_limit(120);
        set_transient("lpagery_sync_running", true, 120);

        foreach ($input_data as $response_entry) {
            $post_config_data = $process["data"];
            $params = $this->inputParamProvider->lpagery_get_input_params_without_images($response_entry);
            $replaced_slug = $this->substitutionHandler->lpagery_substitute($params, $post_config_data["slug"]);
            $replaced_slug = sanitize_title($replaced_slug);
            $slugs_from_sheet[] = $replaced_slug;
        }

        $process_id = $process["id"];
        $result = $this->lpageryDao->lpagery_get_process_posts_slugs($process_id);
        foreach ($result as $post_slug_entry) {
            set_transient("lpagery_sync_running", true, 30);
            set_time_limit(30);

            if (!$post_slug_entry->replaced_slug || in_array($post_slug_entry->replaced_slug, $slugs_from_sheet)) {
                continue;
            }

            error_log("Deleting Page " . $post_slug_entry->replaced_slug);
            $this->lpageryDao->lpagery_delete_process_post($process_id, $post_slug_entry->post_id);
            wp_delete_post($post_slug_entry->post_id, true);
        }
    }

}