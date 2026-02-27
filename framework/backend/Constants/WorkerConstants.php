<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * WorkerConstants - Worker-related constants
 *
 * Defines command line argument names for worker processes.
 * Formats are derived in ArgumentHelper.
 * Also defines message types for worker-daemon communication.
 */
class WorkerConstants
{
    /** @var string Worker ID command line argument name */
    public const string WORKER_ID_ARG = 'worker-id';

    /** @var string Monopolistic worker flag name */
    public const string MONOPOLISTIC_ARG = 'monopolistic';

    // Message types from daemon to worker
    /** @var string Worker registration confirmation */
    public const string MESSAGE_WORKER_REGISTERED = 'worker_registered';

    /** @var string Agent start signal */
    public const string MESSAGE_AGENT_START = 'agent_start';

    /** @var string Agent stop signal */
    public const string MESSAGE_AGENT_STOP = 'agent_stop';

    /** @var string Agent message signal (worker -> daemon) */
    public const string MESSAGE_AGENT_MESSAGE = 'agent_message';

    /** @var string Daemon agent message signal (daemon -> worker) */
    public const string MESSAGE_DAEMON_AGENT_MESSAGE = 'daemon_agent_message';

    /** @var string DB sync created (daemon/worker broadcast) */
    public const string MESSAGE_DB_SYNC_CREATED = 'db_sync_created';

    /** @var string DB sync updated (daemon/worker broadcast) */
    public const string MESSAGE_DB_SYNC_UPDATED = 'db_sync_updated';

    /** @var string DB sync deleted (daemon/worker broadcast) */
    public const string MESSAGE_DB_SYNC_DELETED = 'db_sync_deleted';

    /** @var string RT sync created (daemon/worker broadcast) */
    public const string MESSAGE_RT_SYNC_CREATED = 'rt_sync_created';

    /** @var string RT sync updated (daemon/worker broadcast) */
    public const string MESSAGE_RT_SYNC_UPDATED = 'rt_sync_updated';

    /** @var string RT sync deleted (daemon/worker broadcast) */
    public const string MESSAGE_RT_SYNC_DELETED = 'rt_sync_deleted';

    // Message types from worker to daemon
    /** @var string Worker registration request */
    public const string MESSAGE_WORKER_REGISTER = 'worker_register';

    /** @var string Agent started notification */
    public const string MESSAGE_AGENT_STARTED = 'agent_started';

    /** @var string Agent stopped notification */
    public const string MESSAGE_AGENT_STOPPED = 'agent_stopped';
}
