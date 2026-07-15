<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * CommandConstants - CLI command-channel protocol constants.
 *
 * The command channel is a dedicated socket transport between the CLI and the
 * daemon, separate from the HTTP status endpoint, the WebSocket server, and the
 * worker link. These constants name the request/reply wire keys, the built-in
 * command names, and the reply statuses so the CLI client and the daemon-side
 * server agree on the protocol.
 */
final class CommandConstants
{
    /** @var string Wire key: request/reply correlation id */
    public const string FIELD_CORRELATION_ID = 'correlationId';

    /** @var string Wire key: request command name */
    public const string FIELD_COMMAND = 'command';

    /** @var string Wire key: request/reply payload map */
    public const string FIELD_PAYLOAD = 'payload';

    /** @var string Wire key: reply status */
    public const string FIELD_STATUS = 'status';

    /** @var string Payload key: human-readable error message on a failed reply */
    public const string FIELD_MESSAGE = 'message';

    /** @var string Reply status: command handled successfully */
    public const string STATUS_OK = 'ok';

    /** @var string Reply status: command failed or was not recognized */
    public const string STATUS_ERROR = 'error';

    /** @var string Built-in command: transport health check; the daemon echoes the request payload */
    public const string COMMAND_PING = 'ping';

    /** @var string Built-in command: the daemon answers synchronously with the cluster node snapshot */
    public const string COMMAND_CLUSTER_NODES = 'cluster:nodes';

    /** @var string Built-in command: the daemon re-reads cluster config, refreshes the local node, and re-announces it */
    public const string COMMAND_CLUSTER_RELOAD = 'cluster:reload';

    /** @var string Test-only command: the daemon answers synchronously with a rich cluster/consensus/placement snapshot */
    public const string COMMAND_CLUSTER_INSPECT = 'cluster:test:inspect';
}
