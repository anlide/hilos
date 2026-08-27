<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\NonProductionGate;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\Interface\CommandClientInterface;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;

/**
 * CommandClient - daemon-side representation of a CLI command connection.
 *
 * Parses newline-delimited JSON {@see CommandRequestDTO} messages from the CLI.
 * The built-in `ping` is answered SYNCHRONOUSLY in the master (a transport health
 * check). Any other command is PARKED in the {@see CommandServer} by correlation
 * id and routed to its owning agent as a COMMAND_REQUEST signal; the daemon writes
 * the agent's COMMAND_REPLY back here through {@see writeReply()} once it arrives.
 * A held request that gets no reply within {@see HELD_TIMEOUT_SEC} is failed.
 *
 * Ahead of all of that stands the test-only gate: this is the only place a
 * {@see CommandRequestDTO} enters the backend, so it is the only place from which a
 * production node can refuse the commands that exist to manipulate a test one.
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

            // The environment gate, and deliberately ABOVE every branch below it: three of
            // the test-only commands are answered by the master itself and appear in no
            // agent registry, while the rest are parked and leave as a signal - a gate
            // placed after the split would have to be written twice and would still miss
            // whichever half a later command lands in. What it judges is the command, not
            // the connection: a `ping` and a test-only command down one socket get
            // different answers.
            if (TestOnlyCommandRegistry::isTestOnly($request->command) && !NonProductionGate::admitted()) {
                Logger::warning("Refused test-only command {$request->command}: APP_ENV is production-like or unset");
                $this->writeBuffer .= CommandReplyDTO::error(
                    $request->correlationId,
                    TestOnlyCommandOnProductionException::message($request->command),
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

            if ($request->command === CommandConstants::COMMAND_CLUSTER_CLIENT_ATTACH
                || $request->command === CommandConstants::COMMAND_CLUSTER_CLIENT_DETACH
            ) {
                // Test-only: put an accept key in this node's own set, or take it back out,
                // with no socket behind it (HIL-668). The cluster demo runs headless, so this
                // is the only way to give the mesh a browser to argue about; everything past
                // the socket - the announcement, the lookup, the forward - is the real path.
                $reply = $this->answerClientAttachment($request);
                $this->writeBuffer .= $reply->toJson() . "\n";
                continue;
            }

            if ($request->command === CommandConstants::COMMAND_CLUSTER_CLIENT_SEND
                || $request->command === CommandConstants::COMMAND_CLUSTER_CLIENT_FANOUT
            ) {
                // Test-only: raise the signal an agent would raise for a browser - addressed at
                // one, or fanned out to all - and let the ordinary routing pass decide which
                // node it belongs to. Answered here rather than parked, because the point is the
                // master's own routing and the demo registers no agent to park it at.
                $reply = $this->answerClientSignal($request);
                $this->writeBuffer .= $reply->toJson() . "\n";
                continue;
            }

            if ($request->command === CommandConstants::COMMAND_CLUSTER_DB_ANNOUNCE) {
                // Test-only: raise the DB sync fact a worker raises after writing a row, and let
                // the dispatch pass carry it to the mesh (HIL-670). Answered here for the same
                // reason the client signals above are - what is being exercised is the master's
                // own dispatch, and the cluster demo registers no agent to park it at.
                $reply = $this->answerDbAnnounce($request);
                $this->writeBuffer .= $reply->toJson() . "\n";
                continue;
            }

            if ($request->command === CommandConstants::COMMAND_CLUSTER_AGENT_PLACE) {
                // Test-only: raise the placement request an addressed frame raises for an agent
                // nobody has placed yet (HIL-696), and let the on-demand path pick the node.
                // Answered here rather than parked for the plainest of reasons: the agent it asks
                // for is not running, so there is nobody to park it at.
                $reply = $this->answerAgentPlace($request);
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
     * Attaches or detaches a browser connection on this node's index (HIL-668).
     *
     * @param CommandRequestDTO $request Attach or detach request naming the accept key
     * @return CommandReplyDTO Reply naming the key, or the error to answer instead
     */
    private function answerClientAttachment(CommandRequestDTO $request): CommandReplyDTO
    {
        // external-boundary: a test harness's command line, checked on the very next line
        $acceptKey = $request->payload[CommandConstants::FIELD_ACCEPT_KEY] ?? null;
        if (!is_string($acceptKey) || $acceptKey === '') {
            return CommandReplyDTO::error($request->correlationId, 'Missing acceptKey');
        }

        try {
            $connections = Hilos::$cluster?->clientConnections();
            if ($connections === null) {
                return CommandReplyDTO::error($request->correlationId, 'No cluster connection index on this node');
            }

            if ($request->command === CommandConstants::COMMAND_CLUSTER_CLIENT_ATTACH) {
                $connections->attachLocal($acceptKey);
            } else {
                $connections->detachLocal($acceptKey);
            }
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_ACCEPT_KEY => $acceptKey,
        ]);
    }

    /**
     * Raises the signal a test asks this node to send a browser, addressed or fanned out.
     *
     * The addressed one needs its target; the fan-out names nobody by design, so it takes no
     * accept key at all rather than an ignored one.
     *
     * @param CommandRequestDTO $request Send or fanout request
     * @return CommandReplyDTO Reply naming what was raised, or the error to answer instead
     */
    private function answerClientSignal(CommandRequestDTO $request): CommandReplyDTO
    {
        $addressed = $request->command === CommandConstants::COMMAND_CLUSTER_CLIENT_SEND;
        // external-boundary: a test harness's command line, checked on the very next lines
        $acceptKey = $request->payload[CommandConstants::FIELD_ACCEPT_KEY] ?? null;
        if ($addressed && (!is_string($acceptKey) || $acceptKey === '')) {
            return CommandReplyDTO::error($request->correlationId, 'Missing acceptKey');
        }

        $text = $request->payload[CommandConstants::FIELD_TEXT] ?? null;
        if (!is_string($text) || $text === '') {
            return CommandReplyDTO::error($request->correlationId, 'Missing text');
        }

        try {
            Hilos::$sr->queueSignal(
                signalSource: new SignalSource(SignalSource::DAEMON),
                signalType: new SignalType(
                    $addressed ? SignalTypeConstants::WS_USER : SignalTypeConstants::WS_ALL_CONNECTED,
                ),
                signalName: new SignalName(ClusterCommandConstants::SIGNAL_CLIENT_TEST),
                signalData: new WebSocketSignalData(
                    data: new SignalData([CommandConstants::FIELD_TEXT => $text]),
                    targetAcceptKey: $addressed && is_string($acceptKey) ? $acceptKey : null,
                ),
            );
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_TEXT => $text,
        ]);
    }

    /**
     * Raises the DB sync fact a worker raises after writing a row, so it travels the mesh.
     *
     * The row id is expected to name nothing: the drill is about the frame crossing, and a stand
     * whose nodes carry different schemas cannot ask whether a row landed anyway. Naming a real
     * row would let the drill overwrite a neighbour's copy of somebody's settings, which is a
     * price no acceptance check should pay.
     *
     * The payload is an ordinary update fact, so nothing downstream knows this came from a
     * command line: the announce filter, the frame, the seam check and the apply are the ones
     * production uses.
     *
     * @param CommandRequestDTO $request Announce request naming a collection and a row id
     * @return CommandReplyDTO Reply naming what was raised, or the error to answer instead
     */
    private function answerDbAnnounce(CommandRequestDTO $request): CommandReplyDTO
    {
        // external-boundary: a test harness's command line, checked on the very next lines
        $collection = $request->payload[CommandConstants::FIELD_COLLECTION] ?? null;
        if (!is_string($collection) || $collection === '') {
            return CommandReplyDTO::error($request->correlationId, 'Missing collection');
        }

        // external-boundary: the same command line, checked on the very next lines
        $rowId = $request->payload[CommandConstants::FIELD_ROW_ID] ?? null;
        if (!is_string($rowId) || $rowId === '') {
            return CommandReplyDTO::error($request->correlationId, 'Missing rowId');
        }

        try {
            Hilos::$sr->queueSignal(
                signalSource: new SignalSource(SignalSource::DAEMON),
                signalType: new SignalType(SignalTypeConstants::DB_SYNC_UPDATED),
                signalName: new SignalName(SignalConstants::DB_SYNC_UPDATED),
                signalData: new DbSyncUpdatedSignalData(
                    $collection,
                    $rowId,
                    [ClusterCommandConstants::FIELD_DB_ANNOUNCE_COLUMN => $rowId],
                ),
            );
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_COLLECTION => $collection,
            CommandConstants::FIELD_ROW_ID => $rowId,
        ]);
    }

    /**
     * Asks for an agent to be placed, the way a frame addressed at an unplaced one does.
     *
     * The whole body of it is one call, and that is the point: what the harness needs staged is
     * an agent on a node of the leader's choosing, and the production answer to that already
     * exists. A placement of its own here would be a second placer — the very thing the leader
     * owns the view to prevent — and would also skip the guards the on-demand path carries: an
     * agent already placed, being placed, or refused is left exactly as it is.
     *
     * The index is optional and travels as null for a singleton agent, which is how every other
     * placement API spells the same thing.
     *
     * @param CommandRequestDTO $request Placement request naming an agent type and optional index
     * @return CommandReplyDTO Reply naming what was asked for, or the error to answer instead
     */
    private function answerAgentPlace(CommandRequestDTO $request): CommandReplyDTO
    {
        // external-boundary: a test harness's command line, checked on the very next lines
        $agentType = $request->payload[CommandConstants::FIELD_AGENT_TYPE] ?? null;
        if (!is_string($agentType) || $agentType === '') {
            return CommandReplyDTO::error($request->correlationId, 'Missing agentType');
        }

        // external-boundary: the same command line; absent or null means a singleton agent
        $agentIndex = $request->payload[CommandConstants::FIELD_AGENT_INDEX] ?? null;
        if ($agentIndex !== null && (!is_string($agentIndex) || $agentIndex === '')) {
            return CommandReplyDTO::error($request->correlationId, 'agentIndex must be a non-empty string');
        }

        try {
            $placement = Hilos::$cluster?->placement();
            if ($placement === null) {
                return CommandReplyDTO::error($request->correlationId, 'No placement coordinator on this node');
            }

            $placement->requirePlacement($agentType, $agentIndex);
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_AGENT_TYPE => $agentType,
            CommandConstants::FIELD_AGENT_INDEX => $agentIndex,
        ]);
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
