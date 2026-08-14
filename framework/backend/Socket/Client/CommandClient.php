<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\Interface\CommandClientInterface;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\SocketException;

/**
 * CommandClient - daemon-side representation of a CLI command connection.
 *
 * Parses newline-delimited JSON {@see CommandRequestDTO} messages from the CLI.
 * The built-in `ping` is answered SYNCHRONOUSLY in the master (a transport health
 * check). Any other command is PARKED in the {@see CommandServer} by correlation
 * id and routed to its owning agent as a COMMAND_REQUEST signal; the daemon writes
 * the agent's COMMAND_REPLY back here through {@see writeReply()} once it arrives.
 * A held request that gets no reply within {@see HELD_TIMEOUT_SEC} is failed.
 */
class CommandClient extends AbstractClient implements CommandClientInterface
{
    /** @var float Max seconds to hold a connection waiting for an agent reply */
    private const float HELD_TIMEOUT_SEC = 30.0;

    /** @var CommandServer Owning server holding the held-request registry */
    private CommandServer $server;

    /** @var ?string Correlation id of the request held awaiting an agent reply, or null when not held */
    private ?string $heldCorrelationId = null;

    /** @var float Start time (microtime) of the held request */
    private float $heldSince = 0.0;

    /**
     * Create command client with socket and owning server.
     *
     * @param resource|object $socket Client socket
     * @param CommandServer $server Owning command server
     * @throws EnvException When socket read buffer env value is missing or invalid
     */
    public function __construct($socket, CommandServer $server)
    {
        parent::__construct($socket);
        $this->server = $server;
    }

    /**
     * Parse command requests and either answer `ping` synchronously or park and route.
     *
     * @throws SocketException When the read buffer or JSON depth exceeds limits
     * @throws HilosException When a decoded command line refuses to become a DTO at all
     * @throws InvalidArgumentException When the parked request carries a command that cannot be named
     */
    protected function processReadBuffer(): void
    {
        // While a request is parked awaiting an agent reply, do not parse more.
        if ($this->heldCorrelationId !== null) {
            return;
        }

        while ($this->readBuffer !== '') {
            $message = $this->extractCompleteJsonMessage($this->readBuffer);
            if ($message === null) {
                // Incomplete message, wait for more data
                break;
            }

            // A request that names nothing is refused here, the same way the WebSocket
            // boundary refuses an empty action, page or group. Left through, an empty
            // command reaches new SignalName() and throws where nothing on the read
            // path catches it, and an empty correlation id parks a request no reply
            // can be addressed to. The refusal arrives in three shapes and all are
            // answered rather than rethrown: the reader refuses a line that does not
            // decode or decodes into something other than an object, fromArray()
            // refuses one that omits a field, and the check below refuses one that
            // carries the field empty. What the answer cannot do is address itself:
            // a line too broken to carry a correlation id is replied to with an empty
            // one, and the sender matches it to its request only because
            // AsyncCommandClient reads the first line that arrives.
            try {
                $request = CommandRequestDTO::fromJson($message);
            } catch (InvalidFormatException) {
                $this->writeBuffer .= CommandReplyDTO::error(
                    self::correlationIdOf($message),
                    'Command request must carry a correlation id and a command name',
                )->toJson() . "\n";
                continue;
            }

            if ($request->correlationId === '' || $request->command === '') {
                $this->writeBuffer .= CommandReplyDTO::error(
                    $request->correlationId,
                    'Command request must carry a correlation id and a command name',
                )->toJson() . "\n";
                continue;
            }

            if ($request->command === CommandConstants::COMMAND_PING) {
                $this->writeBuffer .= CommandReplyDTO::ok($request->correlationId, $request->payload)->toJson() . "\n";
                continue;
            }

            if ($request->command === CommandConstants::COMMAND_CLUSTER_NODES) {
                // A misconfigured cluster must reply an error, not throw inside the master loop.
                try {
                    $reply = CommandReplyDTO::ok($request->correlationId, Hilos::$cluster?->snapshot() ?? []);
                } catch (HilosException $e) {
                    $reply = CommandReplyDTO::error($request->correlationId, $e->getMessage());
                }
                $this->writeBuffer .= $reply->toJson() . "\n";
                continue;
            }

            if ($request->command === CommandConstants::COMMAND_CLUSTER_RELOAD) {
                // Rare operator action: re-read config and re-announce on the master.
                // A bad config or disabled cluster must reply an error, not throw here.
                try {
                    $changed = Hilos::$cluster?->reload() ?? false;
                    $payload = Hilos::$cluster?->snapshot() ?? [];
                    $payload[ClusterCommandConstants::FIELD_CHANGED] = $changed;
                    $reply = CommandReplyDTO::ok($request->correlationId, $payload);
                } catch (HilosException $e) {
                    $reply = CommandReplyDTO::error($request->correlationId, $e->getMessage());
                }
                $this->writeBuffer .= $reply->toJson() . "\n";
                continue;
            }

            if ($request->command === CommandConstants::COMMAND_CLUSTER_INSPECT) {
                // Test-only read of the master's cluster/consensus/placement view.
                // A misconfigured cluster must reply an error, not throw inside the master loop.
                try {
                    $reply = CommandReplyDTO::ok($request->correlationId, Hilos::$cluster?->inspect() ?? []);
                } catch (HilosException $e) {
                    $reply = CommandReplyDTO::error($request->correlationId, $e->getMessage());
                }
                $this->writeBuffer .= $reply->toJson() . "\n";
                continue;
            }

            if ($request->command === CliCommands::PROTECTED_MODE_TEST_INSPECT) {
                // Test-only read of the master's own view of protected mode. Answered here and
                // not parked, because parking routes to an agent and a freeze stops every agent
                // but the initiator - the inspector would go silent in the one phase it exists
                // to report on. A subsystem failure must reply an error, not throw in the loop.
                try {
                    $reply = CommandReplyDTO::ok($request->correlationId, $this->server->protectedModeSnapshot());
                } catch (HilosException $e) {
                    $reply = CommandReplyDTO::error($request->correlationId, $e->getMessage());
                }
                $this->writeBuffer .= $reply->toJson() . "\n";
                continue;
            }

            if ($request->command === CommandConstants::COMMAND_CONNECTION_DROP) {
                // Test-only: the master force-closes the live WebSocket connection with the
                // given acceptKey (simulating an unplanned drop). A socket-close failure must
                // reply an error, not throw inside the master loop.
                $acceptKey = $request->payload[CommandConstants::FIELD_ACCEPT_KEY] ?? null;
                if (!is_string($acceptKey) || $acceptKey === '') {
                    $reply = CommandReplyDTO::error($request->correlationId, 'Missing acceptKey');
                } else {
                    try {
                        $dropped = $this->server->dropWebSocketConnection($acceptKey);
                        $reply = CommandReplyDTO::ok($request->correlationId, [
                            CommandConstants::FIELD_ACCEPT_KEY => $acceptKey,
                            CommandConstants::FIELD_DROPPED => $dropped,
                        ]);
                    } catch (HilosException $e) {
                        $reply = CommandReplyDTO::error($request->correlationId, $e->getMessage());
                    }
                }
                $this->writeBuffer .= $reply->toJson() . "\n";
                continue;
            }

            // Async: park, then route to the owning agent; the reply returns via writeReply().
            $this->heldCorrelationId = $request->correlationId;
            $this->heldSince = microtime(true);
            $this->server->hold($request->correlationId, $this);
            Hilos::$sr->queueSignal(
                signalSource: new SignalSource(SignalSource::DAEMON),
                signalType: new SignalType(SignalTypeConstants::COMMAND_REQUEST),
                signalName: new SignalName($request->command),
                signalData: $request,
            );

            return;
        }
    }

    /**
     * Write an agent reply to the held connection and clear the held state.
     *
     * @param CommandReplyDTO $reply Reply to write
     */
    public function writeReply(CommandReplyDTO $reply): void
    {
        $this->writeBuffer .= $reply->toJson() . "\n";
        $this->heldCorrelationId = null;
    }

    /**
     * Fail a held request that has waited longer than HELD_TIMEOUT_SEC.
     */
    public function onTick(): void
    {
        if ($this->heldCorrelationId === null) {
            return;
        }

        if ((microtime(true) - $this->heldSince) >= self::HELD_TIMEOUT_SEC) {
            $correlationId = $this->heldCorrelationId;
            $this->server->forget($correlationId);
            $this->writeReply(CommandReplyDTO::error($correlationId, 'Command timed out'));
        }
    }

    /**
     * Drop the held request from the server registry on disconnect.
     */
    protected function onClose(): void
    {
        if ($this->heldCorrelationId !== null) {
            $this->server->forget($this->heldCorrelationId);
            $this->heldCorrelationId = null;
        }
    }

    /**
     * Reads the correlation id out of a line that could not be hydrated.
     *
     * The refusal still has to be answered, and the CLI on the other end is
     * waiting on this connection: a request refused for a missing command may
     * well carry the id its reply should be addressed to.
     *
     * @param string $message Raw request line
     * @return string Correlation id the line carries, or the empty string when it carries none
     */
    private static function correlationIdOf(string $message): string
    {
        $fields = json_decode($message, true);
        $correlationId = is_array($fields) ? ($fields[CommandConstants::FIELD_CORRELATION_ID] ?? null) : null;

        // external-boundary: the CLI sent a line naming no correlation id, so the reply is unaddressed
        return is_string($correlationId) ? $correlationId : '';
    }
}
