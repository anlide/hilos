<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;
use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * SCAFFOLD/EXAMPLE: exercises the async command round-trip end to end.
 *
 * Sends an `echo` command over the command socket channel; the daemon parks the
 * connection, routes it to the chat agent, and writes the agent's echoed reply
 * back. Proves the held-connection + agent-reply path (admin-grant transport A2)
 * with no grant logic. Test-only: refused on production.
 */
final class EchoCommand extends TestOnlyCommand
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 5000.0;

    /** @var int Poll sleep between ticks in microseconds */
    private const int POLL_INTERVAL_US = 10000;

    public function getName(): string
    {
        return ChatCliCommands::COMMAND_ECHO;
    }

    public function getDescription(): string
    {
        return 'Test-only: echo a message through the daemon command channel via an agent';
    }

    public function getHelp(): string
    {
        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Sends an echo command over the command socket channel. The daemon parks the
connection, routes the command to the chat agent, and writes the agent's echoed
reply back. Exercises the full async command round-trip.

Usage:
  php cli.php test:command:echo [message]
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the echo message
     * @return int Exit code (0 on success)
     */
    protected function run(array $options, array $args): int
    {
        $message = $args[0] ?? 'echo';
        echo "Sending echo through the command channel...\n";

        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: ChatCommandConstants::ECHO,
            payload: [CommandConstants::FIELD_MESSAGE => $message],
        );

        try {
            $client->startRequest($request);

            $startedAtMs = microtime(true) * 1000;
            while (!$client->hasResult()) {
                if ((microtime(true) * 1000 - $startedAtMs) > self::MAX_WAIT_MS) {
                    echo "No reply from daemon (is it running?)\n";
                    return ExitCode::ERROR;
                }

                $client->tick();
                usleep(self::POLL_INTERVAL_US);
            }

            $reply = $client->consumeResult();
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::ERROR;
        }

        if (!$reply->isOk()) {
            $detail = (string) ($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        $echoed = (string) ($reply->payload[CommandConstants::FIELD_MESSAGE] ?? '');
        echo "Reply (ok): {$echoed}\n";

        return ExitCode::SUCCESS;
    }
}
