<?php

namespace LPagery\model;

/**
 * Typed shape of a `lpagery_sync_queue` row — the one row contract that crosses the
 * {@see \LPagery\data\repository\SyncQueueRepository} seam. Callers read typed properties instead of
 * pulling raw `$wpdb` rows and hand-casting `?? false` / `?? null`; the serialize/unserialize and
 * bool/int normalization happen once, here and in the repository, never in the workers or controller.
 *
 * Doubles as the enqueue input: {@see SyncQueueRepository::enqueue_or_refresh()} takes a
 * `SyncQueueItem` whose `data` is the (still unserialized) Row Data and serializes it on write.
 */
class SyncQueueItem
{
    public int $id = 0;
    public int $process_id = 0;
    public ?string $slug = null;
    public int $parent_id = 0;
    /**
     * The background-job discriminator (default {@see \LPagery\service\queue\QueueOperation::SHEET_SYNC}).
     * The worker dispatches on it; the sheet-sync producer's items carry 'sheet_sync', while later
     * producers (background create/update/re-upload/render-mode switch) carry their own operation.
     */
    public string $operation = 'sheet_sync';
    /** @var string|array|null Unserialized Row Data. */
    public $data = null;
    public ?string $creation_id = null;
    public ?string $hashed_payload = null;
    public int $retry = 0;
    public bool $force_update = false;
    public bool $overwrite_manual_changes = false;
    public ?string $status_from_dashboard = null;
    public ?string $publish_timestamp = null;
    public string $existing_page_update_action = 'create';
    public ?string $error = null;

    /**
     * Build a typed item from a raw `lpagery_sync_queue` row (associative array). `data` is
     * unserialized here so no `maybe_unserialize` of a queue row leaks past the repository seam.
     *
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        $item = new self();
        $item->id = (int)($row['id'] ?? 0);
        $item->process_id = (int)($row['process_id'] ?? 0);
        $item->slug = isset($row['slug']) ? (string)$row['slug'] : null;
        $item->parent_id = (int)($row['parent_id'] ?? 0);
        $item->operation = isset($row['operation']) ? (string)$row['operation'] : 'sheet_sync';
        $item->data = isset($row['data']) ? maybe_unserialize($row['data']) : null;
        $item->creation_id = isset($row['creation_id']) ? (string)$row['creation_id'] : null;
        $item->hashed_payload = isset($row['hashed_payload']) ? (string)$row['hashed_payload'] : null;
        $item->retry = (int)($row['retry'] ?? 0);
        $item->force_update = (bool)($row['force_update'] ?? false);
        $item->overwrite_manual_changes = (bool)($row['overwrite_manual_changes'] ?? false);
        $item->status_from_dashboard = isset($row['status_from_dashboard']) ? (string)$row['status_from_dashboard'] : null;
        $item->publish_timestamp = isset($row['publish_timestamp']) ? (string)$row['publish_timestamp'] : null;
        $item->existing_page_update_action = isset($row['existing_page_update_action'])
            ? (string)$row['existing_page_update_action']
            : 'create';
        $item->error = isset($row['error']) ? (string)$row['error'] : null;
        return $item;
    }
}
