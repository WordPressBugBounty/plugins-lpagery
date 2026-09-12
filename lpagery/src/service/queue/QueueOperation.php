<?php

namespace LPagery\service\queue;

/**
 * The background-job operation discriminator carried by every {@see \LPagery\model\SyncQueueItem} and
 * persisted in the `operation` column of `lpagery_sync_queue`. The worker dispatches on it (see
 * {@see QueueOperationDispatcher}), so one queue and one worker serve every producer.
 *
 * Only {@see self::SHEET_SYNC} is wired in Phase 3 (issue #220); the remaining values name the
 * background operations the later phases add producers/handlers for, so the discriminator space is
 * fixed from day one.
 */
final class QueueOperation
{
    public const SHEET_SYNC = 'sheet_sync';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const REUPLOAD = 'reupload';
    public const RENDER_MODE_SWITCH = 'render_mode_switch';
}
