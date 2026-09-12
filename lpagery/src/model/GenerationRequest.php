<?php

namespace LPagery\model;

class GenerationRequest
{
    public int $process_id = 0;
    public ?int $page_id_to_be_updated = null;
    public ?int $update_template_id = null;
    public ?string $client_generated_slug = null;
    public bool $force_update_content = false;
    public bool $overwrite_manual_changes = false;
    public string $existing_page_update_action = 'create';
    public string $status = '-1';
    public ?string $publish_timestamp = null;
    public ?string $hashed_payload = null;
    public ?string $generation_type_override = null;
    /**
     * Materialize (ADR 0019): this run rewrites one already-known Generated Page from Live Mode to
     * Classic Mode. It is free on every tier, so the pipeline resolves the page itself instead of
     * going through the Extended-gated update resolution.
     */
    public bool $is_materialization = false;
    /** @var string|array|null */
    public $data = null;
    public bool $allow_create = true;
    public bool $allow_update = true;

    private function __construct()
    {
    }

    public static function from_post_array(array $post, bool $allow_create = true, bool $allow_update = true): self
    {
        $request = new self();
        $request->process_id = (int)($post['process_id'] ?? 0);
        $request->page_id_to_be_updated = isset($post['page_id_to_be_updated']) ? intval($post['page_id_to_be_updated']) : null;
        $request->update_template_id = isset($post['update_template_id']) ? intval($post['update_template_id']) : null;
        $request->client_generated_slug = isset($post['client_generated_slug']) ? sanitize_text_field($post['client_generated_slug']) : null;
        $request->force_update_content = filter_var($post['force_update_content'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $request->overwrite_manual_changes = filter_var($post['overwrite_manual_changes'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $request->existing_page_update_action = isset($post['existing_page_update_action']) ? sanitize_text_field($post['existing_page_update_action']) : 'create';
        $request->status = sanitize_text_field($post['status'] ?? '-1');
        $request->publish_timestamp = $post['publish_timestamp'] ?? null;
        $request->hashed_payload = $post['hashed_payload'] ?? null;
        $request->generation_type_override = isset($post['lpagery_generation_type_override']) ? sanitize_text_field($post['lpagery_generation_type_override']) : null;
        $request->data = $post['data'] ?? null;
        $request->allow_create = $allow_create;
        $request->allow_update = $allow_update;

        return $request;
    }

    public static function for_queue_item(SyncQueueItem $queue_item, array $google_sheet_data, bool $force_update_content, bool $overwrite_manual_changes): self
    {
        $request = new self();
        $request->process_id = $queue_item->process_id;
        // The repository already unserialized the queue row's Row Data into $queue_item->data.
        $request->data = $queue_item->data;
        $request->force_update_content = $force_update_content;
        $request->overwrite_manual_changes = $overwrite_manual_changes;
        $request->publish_timestamp = $queue_item->publish_timestamp;
        $request->hashed_payload = $queue_item->hashed_payload;
        $request->existing_page_update_action = sanitize_text_field($queue_item->existing_page_update_action);
        $request->status = $queue_item->status_from_dashboard !== null ? sanitize_text_field($queue_item->status_from_dashboard) : '-1';
        $request->allow_create = (bool)($google_sheet_data['add'] ?? false);
        $request->allow_update = (bool)($google_sheet_data['update'] ?? false);

        return $request;
    }

    /**
     * A background create/update/re-upload queue item (issue #220 Phase 4 + Phase 9). Unlike a sheet-sync
     * item there is no `google_sheet_data` to derive the create/update permissions from — a background run
     * mirrors the browser create/update path, which both creates new pages and updates existing ones, so
     * both are allowed. The per-item update semantics (existing-page action, overwrite-manual-changes,
     * force-update, status, publish timestamp) ride on the queue item itself.
     */
    public static function for_background_queue_item(SyncQueueItem $queue_item, bool $force_update_content, bool $overwrite_manual_changes): self
    {
        $request = new self();
        $request->process_id = $queue_item->process_id;
        // The repository already unserialized the queue row's Row Data into $queue_item->data.
        $request->data = $queue_item->data;
        $request->force_update_content = $force_update_content;
        $request->overwrite_manual_changes = $overwrite_manual_changes;
        $request->publish_timestamp = $queue_item->publish_timestamp;
        $request->hashed_payload = $queue_item->hashed_payload;
        $request->existing_page_update_action = sanitize_text_field($queue_item->existing_page_update_action);
        $request->status = $queue_item->status_from_dashboard !== null ? sanitize_text_field($queue_item->status_from_dashboard) : '-1';
        $request->allow_create = true;
        $request->allow_update = true;

        return $request;
    }

    public static function for_materialization(int $post_id, int $process_id): self
    {
        $request = new self();
        $request->process_id = $process_id;
        $request->page_id_to_be_updated = $post_id;
        $request->force_update_content = true;
        $request->overwrite_manual_changes = true;
        $request->existing_page_update_action = 'update';
        $request->generation_type_override = 'classic';
        $request->is_materialization = true;
        $request->allow_create = false;
        $request->allow_update = true;

        return $request;
    }
}
