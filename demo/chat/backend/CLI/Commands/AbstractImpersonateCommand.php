<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\TimeConstants;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Shared base for the impersonation start/stop CLI commands.
 *
 * Sends an impersonation command over the daemon command channel; the daemon
 * parks the connection, routes it to the chat agent (which rebinds the session's
 * identity and writes the reply back), and prints the outcome. Subclasses declare
 * the command name, parse their positional args into a payload, and format the
 * success line. Real operator commands — not test-only.
 */
abstract class AbstractImpersonateCommand implements CommandInterface
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 5000.0;

    /** @var int Poll sleep between ticks in microseconds */
    private const int POLL_INTERVAL_US = 10000;

    /**
     * The command-channel command name this CLI command sends.
     *
     * @return string One of the ChatCommandConstants impersonation command names
     */
    abstract protected function commandName(): string;

    /**
     * Parses positional args into the command payload, or returns null when the
     * args are invalid (the subclass prints its own usage hint before returning).
     *
     * @param list<string> $args Positional CLI args
     * @return ?array<string, mixed> Command payload, or null when the args are invalid
     */
    abstract protected function buildPayload(array $args): ?array;

    /**
     * Formats the human line printed for a successful (ok) reply.
     *
     * @param CommandReplyDTO $reply Ok reply from the agent
     * @return string Single-line success summary
     */
    abstract protected function describeSuccess(CommandReplyDTO $reply): string;

    /**
     * Sends the impersonation command through the channel and prints the outcome.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args parsed by the subclass
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $payload = $this->buildPayload($args);
        if ($payload === null) {
            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $reply = $this->sendCommand($payload);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($reply === null) {
            echo "No reply from daemon (is it running?)\n";
            return ExitCode::ERROR;
        }

        if (!$reply->isOk()) {
            $detail = (string) ($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        echo $this->describeSuccess($reply) . "\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Sends the command over the channel and waits for the reply.
     *
     * @param array<string, mixed> $payload Command payload built by the subclass
     * @return ?CommandReplyDTO Reply, or null on timeout / transport failure
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    private function sendCommand(array $payload): ?CommandReplyDTO
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: $this->commandName(),
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
                usleep(self::POLL_INTERVAL_US);
            }

            return $client->consumeResult();
        } catch (\Throwable) {
            return null;
        }
    }
}
