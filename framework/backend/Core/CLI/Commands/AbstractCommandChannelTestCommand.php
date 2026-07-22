<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Throwable;

/**
 * Base for test-only CLI commands that drive a running agent over the command channel.
 *
 * Extends {@see TestOnlyCommand} (so the whole family refuses on a production-like env) and
 * factors out the {@see AsyncCommandClient} round-trip {@see PingCommand} pioneered: open the
 * socket, send a {@see CommandRequestDTO}, poll until a reply or timeout, and hand the reply
 * back. Subclasses build the payload, name the command, and render the reply.
 */
abstract class AbstractCommandChannelTestCommand extends TestOnlyCommand
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 5000.0;

    /** @var int Poll sleep between ticks in microseconds */
    private const int POLL_INTERVAL_US = 10000;

    /**
     * Sends one command over the channel and waits for its reply.
     *
     * @param string $command Command-channel wire name routed to the owning agent
     * @param array<string, mixed> $payload Request payload delivered to the agent
     * @return ?CommandReplyDTO Reply, or null on timeout / transport failure
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    protected function sendCommand(string $command, array $payload): ?CommandReplyDTO
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: $command,
            payload: $payload,
        );

        try {
            $client->startRequest($request);

            $startedAtMs = microtime(true) * 1000;
            while (!$client->hasResult()) {
                if ((microtime(true) * 1000 - $startedAtMs) > self::MAX_WAIT_MS) {
                    return null;
                }

                $client->tick();
                usleep(self::POLL_INTERVAL_US);
            }

            return $client->consumeResult();
        } catch (Throwable) {
            return null;
        }
    }
}
