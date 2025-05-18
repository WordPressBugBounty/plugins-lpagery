<?php
namespace LPagery\model;

/**
 * Data class for process update settings
 */
class UpdateSettings implements \JsonSerializable
{
    private bool $force_update;
    private bool $overwrite_manual_changes;
    private string $existing_page_update_action;
    private ?string $publish_timestamp;

    /**
     * UpdateSettings constructor
     *
     * @param bool $force_update Whether to force update all pages
     * @param bool $overwrite_manual_changes Whether to overwrite manual changes
     * @param string $existing_page_update_action Action for existing pages (create|update|ignore)
     * @param string|null $publish_timestamp Timestamp for publishing
     */
    public function __construct(
        bool $force_update = false,
        bool $overwrite_manual_changes = false,
        string $existing_page_update_action = 'create',
        ?string $publish_timestamp = null
    ) {
        $this->force_update = $force_update;
        $this->overwrite_manual_changes = $overwrite_manual_changes;
        $this->existing_page_update_action = $existing_page_update_action;
        $this->publish_timestamp = $publish_timestamp;
    }

    /**
     * Create from array
     *
     * @param array|null $data Input data
     * @return self
     */
    public static function fromArray(?array $data): self
    {
        if (!$data) {
            return new self();
        }
        
        $force_update = !empty($data['force_update']) && self::sanitizeBoolean($data['force_update']);
        $overwrite_manual_changes = !empty($data['overwrite_manual_changes']) && self::sanitizeBoolean($data['overwrite_manual_changes']);
        $existing_page_update_action = !empty($data['existing_page_update_action']) ? $data['existing_page_update_action'] : 'create';
        $publish_timestamp = isset($data['publish_timestamp']) ? $data['publish_timestamp'] : null;
        
        return new self(
            $force_update,
            $overwrite_manual_changes,
            $existing_page_update_action,
            $publish_timestamp
        );
    }

    /**
     * Helper method to sanitize boolean values
     *
     * @param mixed $value Input value
     * @return bool Sanitized boolean
     */
    private static function sanitizeBoolean($value): bool
    {
        if (function_exists('rest_sanitize_boolean')) {
            return rest_sanitize_boolean($value);
        }
        
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Check if force update is enabled
     *
     * @return bool
     */
    public function isForceUpdate(): bool
    {
        return $this->force_update;
    }

    /**
     * Check if overwrite manual changes is enabled
     *
     * @return bool
     */
    public function isOverwriteManualChanges(): bool
    {
        return $this->overwrite_manual_changes;
    }

    /**
     * Get existing page update action
     *
     * @return string
     */
    public function getExistingPageUpdateAction(): string
    {
        return $this->existing_page_update_action;
    }

    /**
     * Get publish timestamp
     *
     * @return string|null
     */
    public function getPublishTimestamp(): ?string
    {
        return $this->publish_timestamp;
    }

    /**
     * Create ProcessSheetSyncParams object
     *
     * @param int $process_id Process ID
     * @param string $status Status
     * @return ProcessSheetSyncParams
     */
    public function createSyncParams(int $process_id, string $status): ProcessSheetSyncParams
    {
        return new ProcessSheetSyncParams(
            $process_id,
            $this->force_update,
            $this->overwrite_manual_changes,
            $status,
            $this->existing_page_update_action,
            $this->publish_timestamp
        );
    }

    /**
     * JSON serialization
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'force_update' => $this->force_update,
            'overwrite_manual_changes' => $this->overwrite_manual_changes,
            'existing_page_update_action' => $this->existing_page_update_action,
            'publish_timestamp' => $this->publish_timestamp
        ];
    }
} 