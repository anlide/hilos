<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
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
 *
 * One wait budget serves every caller. Three commands used to carry their own copy of this
 * round-trip with a 2-second budget of their own; the budget a command waits for its daemon is
 * not something a command has an opinion about, and the copies proved it by disagreeing without
 * anyone having decided they should.
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
     * @return CommandChannelResult Reply, or why none arrived
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    protected function sendCommand(string $command, array $payload): CommandChannelResult
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);
        $address = "{$host}:{$port}";

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
                    return CommandChannelResult::timedOut($address);
                }

                $client->tick();
                usleep(CommandConstants::POLL_INTERVAL_US);
            }

            return CommandChannelResult::replied($client->consumeResult(), $address);
        } catch (Throwable) {
            // Every way the socket can fail - refused, reset, unparseable - says the same thing to
            // the operator: the channel is not there. What it is NOT is a daemon that heard the
            // command and stayed silent, and that is the distinction the two texts exist for.
            return CommandChannelResult::unreachable($address);
        }
    }

    /**
     * Prints why a round-trip came back without a reply, and answers with the exit code for it.
     *
     * The one place either sentence is written. Callers print the SUCCESS of their own command,
     * because that is theirs to word; a failure of the transport is the transport's, and the
     * twenty-five hand-copied versions of it were identical only by luck.
     *
     * @param CommandChannelResult $result Failed round-trip; a result carrying a reply is not for this
     * @param string $command Command-channel wire name that went unanswered
     * @return int Exit code to return from the command
     */
    protected function printChannelFailure(CommandChannelResult $result, string $command): int
    {
        $seconds = (int)(self::MAX_WAIT_MS / TimeConstants::MS_PER_SECOND);
        echo match ($result->failure) {
            CommandChannelFailure::TIMEOUT => "The daemon did not answer {$command} within {$seconds}s\n",
            default => "Cannot reach the daemon command channel at {$result->address}\n",
        };

        return ExitCode::ERROR;
    }
}
