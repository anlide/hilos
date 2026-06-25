<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\Interface\CommandClientInterface;

/**
 * CommandServer - socket server for the CLI command channel.
 *
 * Accepts CLI connections that speak newline-delimited JSON
 * {@see \Hilos\Socket\Command\DTO\CommandRequestDTO} /
 * {@see \Hilos\Socket\Command\DTO\CommandReplyDTO}. A dedicated control-plane
 * transport, separate from the HTTP status endpoint and the WebSocket server, so
 * CLI traffic never rides a web protocol. Registered like any other server via
 * {@see \Hilos\Core\Daemon\DaemonManager::registerServer()}.
 *
 * @extends AbstractServer<CommandClientInterface>
 */
class CommandServer extends AbstractServer
{
    /**
     * Called when a new command client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return CommandClientInterface Client instance
     */
    protected function onCreateClient($socket): CommandClientInterface
    {
        return new CommandClient($socket);
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
