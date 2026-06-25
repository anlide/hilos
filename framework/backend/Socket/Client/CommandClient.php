<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\CommandConstants;
use Hilos\Socket\Client\Interface\CommandClientInterface;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\SocketException;

/**
 * CommandClient - daemon-side representation of a CLI command connection.
 *
 * Parses newline-delimited JSON {@see CommandRequestDTO} messages from the CLI and
 * queues a {@see CommandReplyDTO} back. In A1 the daemon answers synchronously in
 * the master: the built-in `ping` health check echoes its payload, proving the
 * socket transport end to end. A2 will park the connection and route real commands
 * to an agent, writing the reply once the agent answers.
 */
class CommandClient extends AbstractClient implements CommandClientInterface
{
    /**
     * Parse complete command requests from the read buffer and queue their replies.
     *
     * @throws SocketException When the read buffer or JSON depth exceeds limits
     */
    protected function processReadBuffer(): void
    {
        while ($this->readBuffer !== '') {
            $message = $this->extractCompleteJsonMessage($this->readBuffer);
            if ($message === null) {
                // Incomplete message, wait for more data
                break;
            }

            $request = CommandRequestDTO::fromJson($message);
            $reply = match ($request->command) {
                CommandConstants::COMMAND_PING => CommandReplyDTO::ok($request->correlationId, $request->payload),
                default => CommandReplyDTO::error($request->correlationId, "Unknown command: {$request->command}"),
            };

            $this->writeBuffer .= $reply->toJson() . "\n";
        }
    }

    /**
     * Periodic tick hook; command clients have no timeout or heartbeat work in A1.
     */
    public function onTick(): void
    {
        // No periodic operations needed for command clients
    }

    /**
     * Connection close hook; no command-specific cleanup is required.
     */
    protected function onClose(): void
    {
        // Command client cleanup if needed
    }
}
