<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * WorkerConstants - Worker-related constants.
 *
 * Defines command line argument names for worker processes.
 * Formats are derived in ArgumentHelper.
 * Also defines message types for worker-daemon communication.
 */
final class WorkerConstants
{
    /** @var string Worker ID command line argument name */
    public const string WORKER_ID_ARG = 'worker-id';

    /** @var string Monopolistic worker flag name */
    public const string MONOPOLISTIC_ARG = 'monopolistic';

    /** @var string Worker process field key */
    public const string FIELD_WORKER_PROCESS = 'process';

    /** @var string Worker type field key */
    public const string FIELD_WORKER_TYPE = 'type';

    /** @var string Worker index field key */
    public const string FIELD_WORKER_INDEX = 'index';

    /** @var string Regular worker type */
    public const string TYPE_REGULAR = 'regular';

    /** @var string Monopolistic worker type */
    public const string TYPE_MONOPOLISTIC = 'monopolistic';

    /** @var string Separator between worker type and index in worker key (format: "type:index") */
    public const string KEY_SEPARATOR = ':';

    /** @var int Maximum number of parts when splitting worker key by KEY_SEPARATOR */
    public const int KEY_MAX_PARTS = 2;

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

    /** @var string DB sync created (daemon/worker broadcast); canonical value in SignalTypeConstants */
    public const string MESSAGE_DB_SYNC_CREATED = SignalTypeConstants::DB_SYNC_CREATED;

    /** @var string DB sync updated (daemon/worker broadcast); canonical value in SignalTypeConstants */
    public const string MESSAGE_DB_SYNC_UPDATED = SignalTypeConstants::DB_SYNC_UPDATED;

    /** @var string DB sync deleted (daemon/worker broadcast); canonical value in SignalTypeConstants */
    public const string MESSAGE_DB_SYNC_DELETED = SignalTypeConstants::DB_SYNC_DELETED;

    /** @var string RT sync created (daemon/worker broadcast); canonical value in SignalTypeConstants */
    public const string MESSAGE_RT_SYNC_CREATED = SignalTypeConstants::RT_SYNC_CREATED;

    /** @var string RT sync updated (daemon/worker broadcast); canonical value in SignalTypeConstants */
    public const string MESSAGE_RT_SYNC_UPDATED = SignalTypeConstants::RT_SYNC_UPDATED;

    /** @var string RT sync deleted (daemon/worker broadcast); canonical value in SignalTypeConstants */
    public const string MESSAGE_RT_SYNC_DELETED = SignalTypeConstants::RT_SYNC_DELETED;

    // Message types from worker to daemon
    /** @var string Worker registration request */
    public const string MESSAGE_WORKER_REGISTER = 'worker_register';

    /** @var string Agent started notification */
    public const string MESSAGE_AGENT_STARTED = 'agent_started';

    /** @var string Agent stopped notification */
    public const string MESSAGE_AGENT_STOPPED = 'agent_stopped';
}
