<?php

namespace LPagery\service\queue;

use Exception;

/**
 * Thrown when a producer (browser run, background enqueue, or sheet-sync trigger) tries to start work
 * on a Page Set that already has a queued/running background operation (issue #220 Phase 5). The
 * per-set lock is single: one active background operation per set, so a conflicting operation is
 * rejected rather than queued behind or last-one-wins.
 *
 * The message is the recognizable in-progress state the frontend surfaces through the standard AJAX
 * error envelope ({success:false, exception:<message>}); {@see Cancel} is the escape hatch that
 * releases the lock.
 */
class BackgroundOperationInProgressException extends Exception
{
    public const MESSAGE = 'A background run is already in progress for this page set. Cancel it before starting another.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
