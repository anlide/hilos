<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestClientAttachCommand - Index an accept key as a browser attached to this node
 *
 * A test-only driver (extends {@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}, so it refuses on a production-like env): it puts an
 * accept key into this node's own half of the cluster connection index, with no socket behind
 * it. That is the only way `demo/cluster` can be driven at all, since it runs headless and has
 * no WebSocket server to accept a browser with — and everything downstream of the socket is the
 * production path, which is what makes the scenario worth running. The key is announced to the
 * other nodes by the ordinary per-tick diff, so it becomes addressable exactly as a real one
 * does (HIL-668).
 *
 * Database-free by contract, like the inspector beside it: it talks to nothing but the local
 * command socket, so it still answers on a node partitioned away from MySQL.
 */
class ClusterTestClientAttachCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:client:attach)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_CLIENT_ATTACH;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Index an accept key as a browser attached to this node (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:client:attach

Description:
  Put an accept key into this node's browser-connection index without a socket behind
  it, so the other nodes learn to address it here. The announcement rides the ordinary
  per-tick diff. Refuses on a production-like environment. Used by the cluster harness,
  which runs headless and has no browser to attach for real.

Usage:
  php cli.php test:cluster:client:attach <acceptKey>

Examples:
  php cli.php test:cluster:client:attach ak-node-a-1
HELP;
    }

    /**
     * Sends the attach request and reports the key this node now holds.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the accept key
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked on the very next line
        $acceptKey = $args[0] ?? '';
        if ($acceptKey === '') {
            echo "Error: acceptKey argument is required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(
                CommandConstants::COMMAND_CLUSTER_CLIENT_ATTACH,
                [CommandConstants::FIELD_ACCEPT_KEY => $acceptKey],
            );
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CommandConstants::COMMAND_CLUSTER_CLIENT_ATTACH);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            $detail = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        echo "Attached {$acceptKey}\n";

        return ExitCode::SUCCESS;
    }
}
