<?php
namespace LPagery\model;

/**
 * Data transfer object for Process upsert parameters
 */
class UpsertProcessParams implements \JsonSerializable
{
    private ?int $post_id;
    private int $process_id;
    private ?string $purpose;
    private ?array $data;
    private ?GoogleSheetData $google_sheet_data;
    private bool $include_parent_as_identifier;
    private ?UpdateSettings $update_settings;
    private string $managing_system;

    public function __construct(
        int $process_id,
        bool $include_parent_as_identifier,
        string $managingSystem,
        ?int $post_id = null,
        ?string $purpose = null,
        ?array $data = null,
        ?GoogleSheetData $google_sheet_data = null,
        ?UpdateSettings $update_settings = null
    ) {
        $this->post_id = $post_id;
        $this->process_id = $process_id;
        $this->purpose = $purpose;
        $this->data = $data;
        $this->google_sheet_data = $google_sheet_data;
        $this->include_parent_as_identifier = $include_parent_as_identifier;
        $this->update_settings = $update_settings;
        $this->managing_system = $managingSystem;
    }

    /**
     * Create from array
     *
     * @param array $params Parameters array
     * @return self
     */
    public static function fromArray(array $params, string $managingSystem): self
    {
        $post_id = isset($params['post_id']) ? (int)$params['post_id'] : null;
        $process_id = isset($params['id']) ? (int)$params['id'] : -1;
        $purpose = isset($params['purpose']) ? sanitize_text_field($params['purpose']) : '';
        $data = $params['data'] ?? null;
        
        $request_google_sheet_data = $params["google_sheet_data"] ?? [];
        $google_sheet_data = GoogleSheetData::fromArray($request_google_sheet_data);
        
        $include_parent_as_identifier = filter_var($params["include_parent_as_identifier"] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        $update_settings = null;
        if ($google_sheet_data && $google_sheet_data->isSyncEnabled()) {
            $update_settings = UpdateSettings::fromArray($params['update_settings'] ?? null);
        }
        
        return new self(
            $process_id,
            $include_parent_as_identifier,
            $managingSystem,
            $post_id,
            $purpose,
            $data,
            $google_sheet_data,
            $update_settings,
        );
    }

    /**
     * Get post ID
     *
     * @return int|null
     */
    public function getPostId(): ?int
    {
        return $this->post_id;
    }

    /**
     * Get process ID
     *
     * @return int
     */
    public function getProcessId(): int
    {
        return $this->process_id;
    }

    /**
     * Get purpose
     *
     * @return string|null
     */
    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    /**
     * Get data
     *
     * @return array|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * Get Google Sheet data
     *
     * @return GoogleSheetData|null
     */
    public function getGoogleSheetData(): ?GoogleSheetData
    {
        return $this->google_sheet_data;
    }

    /**
     * Check if sync is enabled
     *
     * @return bool
     */
    public function isSyncEnabled(): bool
    {
        return $this->google_sheet_data && $this->google_sheet_data->isSyncEnabled();
    }

    /**
     * Check if parent should be included as identifier
     *
     * @return bool
     */
    public function isIncludeParentAsIdentifier(): bool
    {
        return $this->include_parent_as_identifier;
    }

    /**
     * Get update settings
     *
     * @return UpdateSettings|null
     */
    public function getUpdateSettings(): ?UpdateSettings
    {
        return $this->update_settings;
    }

    /**
     * Check if Google Sheet is enabled
     *
     * @return bool
     */
    public function isGoogleSheetEnabled(): bool
    {
        return $this->google_sheet_data !== null && $this->google_sheet_data->isEnabled();
    }

    /**
     * Get Google Sheet data as array for storage
     *
     * @return array|null
     */
    public function getGoogleSheetDataArray(): ?array
    {
        return $this->google_sheet_data ? $this->google_sheet_data->toArray() : null;
    }

    /**
     * Create ProcessSheetSyncParams for scheduling
     *
     * @param int $process_id Process ID after upsert
     * @param string $status Status
     * @return ProcessSheetSyncParams|null
     */
    public function createSyncParams(int $process_id, string $status): ?ProcessSheetSyncParams
    {
        if (!$this->update_settings) {
            return null;
        }
        
        return $this->update_settings->createSyncParams($process_id, $status);
    }
    public function getManagingsystem(){
        return $this->managing_system;
    }

    /**
     * JSON serialization
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'post_id' => $this->post_id,
            'process_id' => $this->process_id,
            'purpose' => $this->purpose,
            'data' => $this->data,
            'google_sheet_data' => $this->google_sheet_data,
            'include_parent_as_identifier' => $this->include_parent_as_identifier,
            'update_settings' => $this->update_settings,
            'managing_system' => $this->managing_system
        ];
    }
} 