<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestClientFanoutCommand - Broadcast a signal to every browser of the cluster
 *
 * The unaddressed twin of {@see ClusterTestClientSendCommand}: it raises a broadcast, which
 * names no browser at all, and every node expands it against its own subscriptions and its own
 * connections. What a scenario asserts on is that EVERY node reports the arrival through
 * `test:cluster:inspect`, because a fan-out that only reached the browsers of the node it was
 * raised on is precisely the defect this closes (HIL-668). Test-only ({@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}), and database-free: it talks to nothing but the
 * local command socket.
 */
class ClusterTestClientFanoutCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:client:fanout)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_CLIENT_FANOUT;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Broadcast a signal to every browser of the cluster (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:client:fanout

Description:
  Raise a broadcast for every browser of the cluster from this node. Each node expands it
  against its own connections, so every one of them - not just this one - reports the
  arrival through test:cluster:inspect. Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:client:fanout <text>

Examples:
  php cli.php test:cluster:client:fanout hello-everyone
HELP;
    }

    /**
     * Sends the fan-out request and reports what was raised.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the text
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked on the very next line
        $text = $args[0] ?? '';
        if ($text === '') {
            echo "Error: text argument is required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(
                CommandConstants::COMMAND_CLUSTER_CLIENT_FANOUT,
                [CommandConstants::FIELD_TEXT => $text],
            );
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CommandConstants::COMMAND_CLUSTER_CLIENT_FANOUT);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        echo "Fanned out from this node\n";

        return ExitCode::SUCCESS;
    }
}
