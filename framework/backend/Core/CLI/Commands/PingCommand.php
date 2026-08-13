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
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Throwable;

/**
 * PingCommand - probe the daemon command channel.
 *
 * Sends a `ping` over the dedicated command socket and prints the echoed reply.
 * A health check for the CLI<->daemon command transport (the AsyncCommandClient /
 * CommandServer round-trip), separate from the HTTP `daemon:status` endpoint.
 */
class PingCommand implements CommandInterface
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 2000.0;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (daemon:ping)
     */
    public function getName(): string
    {
        return 'daemon:ping';
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Ping the daemon over the command channel';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: daemon:ping

Description:
  Probe the CLI<->daemon command channel. Sends a ping over the dedicated
  command socket and prints the echoed reply. Useful to verify the command
  transport is up, independently of the HTTP status endpoint.

Usage:
  php cli.php daemon:ping [message]

Examples:
  php cli.php daemon:ping
  php cli.php daemon:ping hello
HELP;
    }

    /**
     * Execute ping command.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the echo message
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $message = $args[0] ?? CommandConstants::COMMAND_PING;
        echo "Pinging daemon command channel...\n";

        try {
            $reply = $this->sendPing($message);
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

        // The echo of the very field this command put on the wire, mirrored back by the master.
        // A reply without it is a broken command channel, not a ping with nothing to say — and
        // printing "Reply (ok): " would read as a healthy daemon.
        $echoed = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null;
        if (!is_string($echoed)) {
            echo "Command failed: the reply carries no echoed message\n";

            return ExitCode::ERROR;
        }

        echo "Reply (ok): {$echoed}\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Sends a ping over the command channel and waits for the reply.
     *
     * Protected for the same reason the sibling commands take their round-trip from a
     * protected trait method: it is the seam a test stands a canned reply in at.
     *
     * @param string $message Echo message
     * @return ?CommandReplyDTO Reply, or null on timeout / transport failure
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    protected function sendPing(string $message): ?CommandReplyDTO
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: CommandConstants::COMMAND_PING,
            payload: [CommandConstants::FIELD_MESSAGE => $message],
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
