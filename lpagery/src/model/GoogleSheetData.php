<?php
namespace LPagery\model;

/**
 * Data class for Google Sheet integration parameters
 */
class GoogleSheetData implements \JsonSerializable
{
    private string $url;
    private bool $add;
    private bool $update;
    private bool $delete;
    private bool $enabled;
    private bool $sync_enabled;

    /**
     * GoogleSheetData constructor
     *
     * @param string $url Google Sheet URL
     * @param bool $add Whether to add new pages
     * @param bool $update Whether to update existing pages
     * @param bool $delete Whether to delete removed pages
     * @param bool $enabled Whether Google Sheet integration is enabled
     * @param bool $sync_enabled Whether automatic sync is enabled
     */
    public function __construct(
        string $url = '',
        bool $add = false,
        bool $update = false,
        bool $delete = false,
        bool $enabled = false,
        bool $sync_enabled = false
    ) {
        $this->url = $url;
        $this->add = $add;
        $this->update = $update;
        $this->delete = $delete;
        $this->enabled = $enabled;
        $this->sync_enabled = $sync_enabled;
    }

    /**
     * Create from array
     *
     * @param array $data Input data
     * @return self|null Returns null if not enabled or no valid URL
     */
    public static function fromArray(array $data): ?self
    {
        $enabled = filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $url = filter_var(urldecode($data['url'] ?? ''), FILTER_VALIDATE_URL);
        
        if (!$enabled || !$url) {
            return null;
        }
        
        $add = filter_var($data['add'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $update = filter_var($data['update'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $delete = filter_var($data['delete'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sync_enabled = filter_var($data['sync_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        return new self($url, $add, $update, $delete, $enabled, $sync_enabled);
    }

    /**
     * Get URL
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Check if adding pages is enabled
     *
     * @return bool
     */
    public function isAddEnabled(): bool
    {
        return $this->add;
    }

    /**
     * Check if updating pages is enabled
     *
     * @return bool
     */
    public function isUpdateEnabled(): bool
    {
        return $this->update;
    }

    /**
     * Check if deleting pages is enabled
     *
     * @return bool
     */
    public function isDeleteEnabled(): bool
    {
        return $this->delete;
    }

    /**
     * Check if Google Sheet integration is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if automatic sync is enabled
     *
     * @return bool
     */
    public function isSyncEnabled(): bool
    {
        return $this->sync_enabled;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'add' => $this->add,
            'update' => $this->update,
            'delete' => $this->delete
        ];
    }

    /**
     * JSON serialization
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'url' => $this->url,
            'add' => $this->add,
            'update' => $this->update,
            'delete' => $this->delete,
            'enabled' => $this->enabled,
            'sync_enabled' => $this->sync_enabled
        ];
    }
} 