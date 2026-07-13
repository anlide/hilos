<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
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

            $request = CommandRequestDTO::fromJson($message);

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
}
