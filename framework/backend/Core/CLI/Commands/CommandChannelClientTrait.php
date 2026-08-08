<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Throwable;

/**
 * The {@see AsyncCommandClient} round-trip a CLI command uses to drive a running agent.
 *
 * A trait rather than a base class because the two callers cannot share an ancestor: the
 * test-only family ({@see AbstractCommandChannelTestCommand}) must keep extending
 * {@see TestOnlyCommand} so it refuses on a production-like environment, while an operator
 * command like {@see BackupVerifyCommand} is a plain {@see CommandInterface} that must NOT
 * refuse there - reaching the daemon is the only thing they have in common.
 */
trait CommandChannelClientTrait
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 5000.0;

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

            $startedAtMs = microtime(true) * TimeConstants::MS_PER_SECOND;
            while (!$client->hasResult()) {
                if ((microtime(true) * TimeConstants::MS_PER_SECOND - $startedAtMs) > self::MAX_WAIT_MS) {
                    return null;
                }

                $client->tick();
                usleep(CommandConstants::POLL_INTERVAL_US);
            }

            return $client->consumeResult();
        } catch (Throwable) {
            return null;
        }
    }
}
