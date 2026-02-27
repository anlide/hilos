<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * SignalConstants - Signal name constants
 *
 * Defines standard signal names used throughout the framework.
 */
class SignalConstants
{
    /** @var string Workers ready signal - sent when initial workers are ready */
    public const string WORKERS_READY = 'workers_ready';

    /** @var string Initial agents start signal - queued by WorkerServer when workers are ready */
    public const string INITIAL_AGENTS_START = 'initial_agents_start';

    /** @var string DB sync signal names (operation) */
    public const string DB_SYNC_CREATED = 'db_sync_created';
    public const string DB_SYNC_UPDATED = 'db_sync_updated';
    public const string DB_SYNC_DELETED = 'db_sync_deleted';

    /** @var string RT sync signal names (operation) */
    public const string RT_SYNC_CREATED = 'rt_sync_created';
    public const string RT_SYNC_UPDATED = 'rt_sync_updated';
    public const string RT_SYNC_DELETED = 'rt_sync_deleted';
}
