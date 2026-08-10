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

/**
 * ConnectionTestDropCommand - force-close a live WebSocket connection by acceptKey.
 *
 * A test-only simulator (extends {@see TestOnlyCommand}, so it refuses on a
 * production-like env): e2e asserts on what a real dropped connection produces - the
 * header reconnect indicator flipping reconnecting->connected, and the master's
 * orphan-reconcile (presence decrement, subscription cleanup) firing on the dead socket.
 * The sockets live in the master, so this sends `connection:test:drop` over the command
 * channel; the master finds the matching live client and closes it, then answers whether a
 * connection was actually dropped.
 */
class ConnectionTestDropCommand extends TestOnlyCommand
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 2000.0;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (connection:test:drop)
     */
    public function getName(): string
    {
        return CommandConstants::COMMAND_CONNECTION_DROP;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Force-close a live WebSocket connection by acceptKey (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: connection:test:drop

Description:
  Force-close the live WebSocket connection with the given acceptKey, simulating an
  unplanned drop. The master runs the normal disconnect path, so presence decrements
  and subscriptions are cleaned up, and the client sees the socket die and reconnects.
  Refuses on a production-like environment. Used by e2e to exercise the reconnect
  indicator and the orphan-reconcile.

Usage:
  php cli.php connection:test:drop <acceptKey>

Examples:
  php cli.php connection:test:drop a1b2c3d4e5f6
HELP;
    }

    /**
     * Sends the drop request and reports whether a live connection was closed.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the target acceptKey
     * @return int Exit code (0 when a connection was dropped)
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the very next line
        $acceptKey = $args[0] ?? '';
        if ($acceptKey === '') {
            echo "Error: acceptKey argument is required\n";
            return ExitCode::ERROR;
        }

        try {
            $reply = $this->sendRequest($acceptKey);
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

        $dropped = (bool) ($reply->payload[CommandConstants::FIELD_DROPPED] ?? false);
        if (!$dropped) {
            echo "No live connection with acceptKey {$acceptKey}\n";
            return ExitCode::ERROR;
        }

        echo "Dropped connection {$acceptKey}\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Sends connection:test:drop over the command channel and waits for the reply.
     *
     * @param string $acceptKey Target connection identifier
     * @return ?CommandReplyDTO Reply, or null on timeout / transport failure
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    private function sendRequest(string $acceptKey): ?CommandReplyDTO
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: CommandConstants::COMMAND_CONNECTION_DROP,
            payload: [CommandConstants::FIELD_ACCEPT_KEY => $acceptKey],
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
        } catch (\Throwable) {
            return null;
        }
    }
}
