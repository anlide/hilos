<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ConnectionTestDropCommand - force-close a live WebSocket connection by acceptKey.
 *
 * A test-only simulator (extends {@see TestOnlyCommand}, so it refuses on a
 * production-like env): e2e asserts on what a real dropped connection produces - the
 * header reconnect indicator flipping reconnecting->connected, and the master's
 * orphan-reconcile (presence decrement, subscription cleanup) firing on the dead socket.
 * The sockets live in the master, so this sends `test:connection:drop` over the command
 * channel; the master finds the matching live client and closes it, then answers whether a
 * connection was actually dropped.
 */
class ConnectionTestDropCommand extends AbstractCommandChannelTestCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:connection:drop)
     */
    public function getName(): string
    {
        return CliCommands::CONNECTION_TEST_DROP;
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
Command: test:connection:drop

Description:
  Force-close the live WebSocket connection with the given acceptKey, simulating an
  unplanned drop. The master runs the normal disconnect path, so presence decrements
  and subscriptions are cleaned up, and the client sees the socket die and reconnects.
  Refuses on a production-like environment. Used by e2e to exercise the reconnect
  indicator and the orphan-reconcile.

Usage:
  php cli.php test:connection:drop <acceptKey>

Examples:
  php cli.php test:connection:drop a1b2c3d4e5f6
HELP;
    }

    /**
     * Sends the drop request and reports whether a live connection was closed.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the target acceptKey
     * @return int Exit code (0 when a connection was dropped)
     * @throws CommandException When the command name is not registered as test-only
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
            $result = $this->sendCommand(
                CliCommands::CONNECTION_TEST_DROP,
                [CommandConstants::FIELD_ACCEPT_KEY => $acceptKey],
            );
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CONNECTION_TEST_DROP);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $dropped = (bool) ($reply->payload[CommandConstants::FIELD_DROPPED] ?? false);
        if (!$dropped) {
            echo "No live connection with acceptKey {$acceptKey}\n";
            return ExitCode::ERROR;
        }

        echo "Dropped connection {$acceptKey}\n";

        return ExitCode::SUCCESS;
    }
}
