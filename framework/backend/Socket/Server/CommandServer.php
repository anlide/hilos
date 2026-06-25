<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\Interface\CommandClientInterface;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * CommandServer - socket server for the CLI command channel.
 *
 * Accepts CLI connections that speak newline-delimited JSON
 * {@see CommandRequestDTO} /
 * {@see CommandReplyDTO}. A dedicated control-plane transport, separate from the
 * HTTP status endpoint and the WebSocket server, so CLI traffic never rides a web
 * protocol. Registered like any other server via
 * {@see DaemonManager::registerServer()}.
 *
 * Owns the held-request registry: a command routed to an agent parks its
 * {@see CommandClient} here by correlation id until the agent's reply arrives,
 * which DaemonManager delivers through {@see deliver()}.
 *
 * @extends AbstractServer<CommandClientInterface>
 */
class CommandServer extends AbstractServer
{
    /** @var array<string, CommandClient> Held command clients awaiting an agent reply, keyed by correlation id */
    private array $heldRequests = [];

    /**
     * Called when a new command client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return CommandClientInterface Client instance
     */
    protected function onCreateClient($socket): CommandClientInterface
    {
        return new CommandClient($socket, $this);
    }

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return 'Command Server';
    }

    /**
     * Parks a command client awaiting an agent reply, keyed by correlation id.
     *
     * @param string $correlationId Correlation id of the pending request
     * @param CommandClient $client Held client
     */
    public function hold(string $correlationId, CommandClient $client): void
    {
        $this->heldRequests[$correlationId] = $client;
    }

    /**
     * Drops a held command client without delivering (timeout or disconnect).
     *
     * @param string $correlationId Correlation id to drop
     */
    public function forget(string $correlationId): void
    {
        unset($this->heldRequests[$correlationId]);
    }

    /**
     * Delivers an agent reply to the held command client and drops it.
     *
     * @param string $correlationId Correlation id of the originating request
     * @param CommandReplyDTO $reply Agent reply to write
     */
    public function deliver(string $correlationId, CommandReplyDTO $reply): void
    {
        $client = $this->heldRequests[$correlationId] ?? null;
        if ($client === null) {
            return;
        }

        unset($this->heldRequests[$correlationId]);
        $client->writeReply($reply);
    }

    /**
     * Check if server is ready to shutdown.
     *
     * Ready once all command clients have disconnected.
     *
     * @return bool True when no clients are connected
     */
    public function isReadyToShutdown(): bool
    {
        return empty($this->clients);
    }

    /**
     * Called when server is started.
     */
    protected function onStart(): void
    {
        // Command server has no specific startup logic
    }
}
