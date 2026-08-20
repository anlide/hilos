<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Socket\Command\TestOnlyCommandRegistry;

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

    /** @var string Payload key: the daemon-minted connection identifier a drop request targets */
    public const string FIELD_ACCEPT_KEY = 'acceptKey';

    /** @var string Payload key: reply flag telling whether a live connection was found and dropped */
    public const string FIELD_DROPPED = 'dropped';

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
    public const string COMMAND_CLUSTER_INSPECT = 'test:cluster:inspect';

    /** @var string Test-only command: the master force-closes the live WebSocket connection with the given acceptKey */
    public const string COMMAND_CONNECTION_DROP = 'test:connection:drop';

    /**
     * The prefix every test-only command name carries on the wire.
     *
     * The human-readable half of the test-only contract, and the reason it exists at all: a
     * flag in a registry is invisible to whoever reads a command name in a log line, a compose
     * file, or a review diff. Topology validation sews the two halves together in both
     * directions, so neither can be forgotten alone.
     *
     * @var string Wire-name prefix of a test-only command
     */
    public const string TEST_ONLY_PREFIX = 'test:';

    /**
     * The test-only commands the master answers itself, which therefore carry no flag anywhere else.
     *
     * Every other test-only command is declared by the agent that owns it, in its own
     * AGENT_COMMANDS entry ({@see AgentCommandConfigKey::TEST_ONLY}). These three appear in
     * no agent registry at all - they are handled in the master's own branches, because a
     * freeze stops every agent but its initiator and because dropping a live socket is the
     * master's own hand - so this is the one place that can declare them. The socket gate
     * reads the union of both halves through {@see TestOnlyCommandRegistry}.
     *
     * @var list<string> Command names the master answers and refuses on a production-like node
     */
    public const array MASTER_TEST_ONLY_COMMANDS = [
        self::COMMAND_CLUSTER_INSPECT,
        CliCommands::PROTECTED_MODE_TEST_INSPECT,
        self::COMMAND_CONNECTION_DROP,
    ];

    /**
     * How often a CLI client looks for the reply while it waits, in microseconds.
     *
     * The cadence belongs to the transport, not to any one command, and it lives
     * here rather than in CommandChannelClientTrait because PHP forbids reading a
     * trait constant without composing the trait — and the commands that poll by
     * hand do not compose it. The wait BUDGET is a different matter: it is a
     * property of the command and stays where the command declares it.
     *
     * @var int Poll sleep between client ticks, in microseconds
     */
    public const int POLL_INTERVAL_US = 10000;
}
