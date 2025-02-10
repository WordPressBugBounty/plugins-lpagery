<?php
namespace LPagery\model;
class ProcessSheetSyncParams implements  \JsonSerializable
{
    private int $process_id;
    private bool $force_update;
    private bool $overwrite_manual_changes;
    private string $new_status;
    private string $existing_page_update_action;
    private ?string $publish_timestamp;

    public static function processOnly(int $process_id): ProcessSheetSyncParams
    {
        return new ProcessSheetSyncParams($process_id, false, false, '-1', '',null);
    }

    public function __construct($process_id, $force_update, $overwrite_manual_changes,  $new_status,$existing_page_update_action, $publish_timestamp)
    {
        $this->process_id = $process_id;
        $this->force_update = $force_update;
        $this->overwrite_manual_changes = $overwrite_manual_changes;
        $this->new_status = $new_status;
        $this->publish_timestamp = $publish_timestamp;
        $this->existing_page_update_action = $existing_page_update_action;
    }

    public function getProcessId()
    {
        return $this->process_id;
    }

    public function getForceUpdate()
    {
        return $this->force_update;
    }

    public function getNewStatus()
    {
        return $this->new_status;
    }

    public function getOverwriteManualChanges()
    {
        return $this->overwrite_manual_changes;
    }
    public function getExistingPageUpdateAction()
    {
        return $this->existing_page_update_action;
    }

    public function getPublishTimestamp()
    {
        return $this->publish_timestamp;
    }

    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    public function __unserialize(array $data): void
    {
        if(!$data) {
            $data  = [];
        }
        $this->process_id = $data['process_id'] ?? 0;
        $this->force_update = $data['force_update'] ?? false;
        $this->overwrite_manual_changes = $data['overwrite_manual_changes'] ?? false;
        $this->new_status = $data['new_status'] ?? '-1';
        $this->publish_timestamp = $data['publish_timestamp'];
        $this->existing_page_update_action = $data['existing_page_update_action'] ?? 'create';
    }

    public function jsonSerialize(): array
    {
        return [
            'process_id' => $this->process_id,
            'force_update' => $this->force_update,
            'overwrite_manual_changes' => $this->overwrite_manual_changes,
            'new_status' => $this->new_status,
            'publish_timestamp' => $this->publish_timestamp,
            'existing_page_update_action' => $this->existing_page_update_action
        ];
    }
}
