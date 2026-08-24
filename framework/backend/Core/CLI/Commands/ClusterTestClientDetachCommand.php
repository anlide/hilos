<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestClientDetachCommand - Take an attached accept key back off this node
 *
 * The other half of {@see ClusterTestClientAttachCommand}: it takes a key attached through the
 * test door back out of this node's set, and the next per-tick diff announces the close to the
 * mesh. What a scenario asserts on afterwards is that the other nodes stop addressing it here —
 * a key that stayed indexed after its browser left is the ghost the whole diff exists to prevent
 * (HIL-668). Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}), and
 * database-free: it talks to nothing but the local command socket.
 */
class ClusterTestClientDetachCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:client:detach)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_CLIENT_DETACH;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Take an attached accept key back off this node (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:client:detach

Description:
  Take an accept key attached through the test door back out of this node's
  browser-connection index. The close is announced by the ordinary per-tick diff, so the
  other nodes stop addressing it here. Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:client:detach <acceptKey>

Examples:
  php cli.php test:cluster:client:detach ak-node-a-1
HELP;
    }

    /**
     * Sends the detach request and reports the key this node has let go.
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
            $reply = $this->sendCommand(
                CommandConstants::COMMAND_CLUSTER_CLIENT_DETACH,
                [CommandConstants::FIELD_ACCEPT_KEY => $acceptKey],
            );
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($reply === null) {
            echo "No reply from daemon (is it running?)\n";
            return ExitCode::ERROR;
        }

        if (!$reply->isOk()) {
            $detail = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        echo "Detached {$acceptKey}\n";

        return ExitCode::SUCCESS;
    }
}
