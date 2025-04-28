<?php

namespace LPagery\service\preparation;

use LPagery\service\InstallationDateHandler;
use LPagery\service\settings\SettingsController;
use LPagery\model\BaseParams;
use LPagery\model\PageCreationDashboardSettings;
use LPagery\model\Params;
use LPagery\utils\Utils;
class InputParamProvider {
    private static ?InputParamProvider $instance = null;

    private SettingsController $settingsController;

    private InstallationDateHandler $installationDateHandler;

    private ?InputParamMediaProvider $paramMediaProvider;

    public function __construct( SettingsController $settingsController, InstallationDateHandler $installationDateHandler, ?InputParamMediaProvider $paramMediaProvider ) {
        $this->settingsController = $settingsController;
        $this->installationDateHandler = $installationDateHandler;
        $this->paramMediaProvider = $paramMediaProvider;
    }

    public static function get_instance( SettingsController $settingsController, InstallationDateHandler $installationDateHandler, ?InputParamMediaProvider $paramMediaProvider ) {
        if ( null === self::$instance ) {
            self::$instance = new self($settingsController, $installationDateHandler, $paramMediaProvider);
        }
        return self::$instance;
    }

    public function lpagery_provide_input_params(
        $json_data,
        $process_id,
        $source_post_id,
        PageCreationDashboardSettings $post_settings,
        bool $force_update_content,
        bool $overwrite_manual_changes,
        bool $include_parent_as_identifier,
        string $existingPageUpdateAction
    ) : Params {
        $base_params = self::lpagery_get_input_params_without_images( $json_data );
        $source_attachment_ids = array();
        $target_attachment_ids = array();
        $keys = $base_params->keys;
        $values = $base_params->values;
        $numeric_keys = $base_params->numeric_keys;
        $numeric_values = $base_params->numeric_values;
        $image_keys = array();
        $image_values = array();
        if ( lpagery_fs()->is_plan_or_trial__premium_only( 'extended' ) && $this->settingsController->isImageProcessingEnabled( $process_id ) && $this->paramMediaProvider ) {
            list( $image_keys, $image_values, $source_attachment_ids, $target_attachment_ids ) = $this->paramMediaProvider->provideMediaParams( $base_params, $source_post_id );
        }
        $params = new Params();
        $params->keys = $keys;
        $params->values = $values;
        $params->image_keys = $image_keys;
        $params->image_values = $image_values;
        $params->numeric_keys = $numeric_keys;
        $params->numeric_values = $numeric_values;
        $params->spintax_enabled = $this->settingsController->isSpintaxEnabled( $process_id );
        $params->image_processing_enabled = $this->settingsController->isImageProcessingEnabled( $process_id );
        $params->author_id = $this->settingsController->getAuthorId( $process_id );
        $params->source_attachment_ids = $source_attachment_ids;
        $params->target_attachment_ids = $target_attachment_ids;
        $params->raw_data = $json_data;
        $params->process_id = $process_id;
        $params->settings = $post_settings;
        $params->force_update_content = $force_update_content;
        $params->overwrite_manual_changes = $overwrite_manual_changes;
        $params->include_parent_as_identifier = $include_parent_as_identifier;
        if ( in_array( $existingPageUpdateAction, ['create', "ignore", "update"] ) ) {
            $params->existing_page_update_action = $existingPageUpdateAction;
        } else {
            $params->existing_page_update_action = "create";
        }
        return $params;
    }

    /**
     * @param $json_data
     * @return BaseParams
     */
    public function lpagery_get_input_params_without_images( $json_data ) : BaseParams {
        $keys = array();
        $values = array();
        $numeric_keys = array();
        $numeric_values = array();
        $index = 0;
        $placeholder_limit = $this->installationDateHandler->get_placeholder_counts();
        foreach ( $json_data as $key => $value ) {
            if ( $key == "" ) {
                continue;
            }
            if ( is_null( $value ) ) {
                $value = "";
            }
            if ( $key !== 'lpagery_id' ) {
                $index++;
            }
            if ( $placeholder_limit !== null && $index > $placeholder_limit ) {
                break;
            }
            $prefix = ( !str_starts_with( $key, "{" ) ? "{" : "" );
            $suffix = ( !str_ends_with( $key, "}" ) ? "}" : "" );
            $keys[] = $prefix . $key . $suffix;
            $values[] = $value;
            if ( is_numeric( $key ) && is_numeric( $value ) ) {
                $numeric_keys[] = $key;
                $numeric_values[] = $value;
            }
        }
        $baseParams = new BaseParams();
        $baseParams->keys = $keys;
        $baseParams->values = $values;
        $baseParams->numeric_keys = $numeric_keys;
        $baseParams->numeric_values = $numeric_values;
        $baseParams->raw_data = $json_data;
        return $baseParams;
    }

}
