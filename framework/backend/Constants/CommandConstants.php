<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Backup\BackupConstants;
use Hilos\Socket\Command\TestOnlyCommandRegistry;

/**
 * CommandConstants - CLI command-channel protocol constants.
 *
 * The command channel is a dedicated socket transport between the CLI and the
 * daemon, separate from the HTTP status endpoint, the WebSocket server, and the
 * worker link. These constants name the request/reply wire keys, the payload keys,
 * and the reply statuses so the CLI client and the daemon-side server agree on the
 * protocol.
 *
 * Command names are not among them. A name is declared once, by the constant class of
 * the subsystem the command belongs to - {@see CliCommands} for the framework's,
 * {@see BackupConstants} for backup's own. Twelve names used to stand here as well as
 * there, with the same value in both places, and that cost a real drift: the registry
 * registered `test:cluster:inspect` under one constant while the command's getName()
 * answered with the other. The one name left is {@see self::COMMAND_PING}, and it is
 * not a duplicate of anything - see its own note.
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

    /**
     * The wire name of the transport health check; the daemon echoes the request payload.
     *
     * The one command name this class still declares, because it is the one that has no twin:
     * the operator types {@see CliCommands::DAEMON_PING} and what goes on the wire is `ping`.
     * Two different values, one for the terminal and one for the protocol, so naming both is
     * not saying the same thing twice.
     *
     * @var string Built-in command: transport health check; the daemon echoes the request payload
     */
    public const string COMMAND_PING = 'ping';

    /** @var string Request payload key: text a test client signal carries */
    public const string FIELD_TEXT = 'text';

    /** @var string Request payload key: database collection a test DB announcement names */
    public const string FIELD_COLLECTION = 'collection';

    /** @var string Request payload key: row id a test DB announcement names */
    public const string FIELD_ROW_ID = 'rowId';

    /** @var string Request payload key: settings key a test DB write or read names */
    public const string FIELD_SETTING_KEY = 'settingKey';

    /** @var string Request payload key: settings value a test DB write carries, or a read answers with */
    public const string FIELD_SETTING_VALUE = 'settingValue';

    /** @var string Request payload key: agent type a test placement request names */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Request payload key: agent index a test placement request names, or null */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /**
     * The prefix every test-only command name carries on the wire.
     *
     * The whole declaration since HIL-742, and the reason it is the one kept: a flag in a
     * registry is invisible to whoever reads a command name in a log line, a compose file, or
     * a review diff, and both holes this rule ever had were found in exactly those places.
     * {@see TestOnlyCommandRegistry} is where the prefix is read; a unit guard holds the other
     * direction, that a command refusing on production is named with it.
     *
     * @var string Wire-name prefix of a test-only command
     */
    public const string TEST_ONLY_PREFIX = 'test:';

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
