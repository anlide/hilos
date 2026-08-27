<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestClientSendCommand - Send a signal to one browser, wherever in the cluster it hangs
 *
 * A test-only driver (extends {@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}, so it refuses on a production-like env): it raises
 * on this node the addressed signal an agent would raise for a browser, and lets the ordinary
 * routing pass decide where that browser is. Run against a node that does NOT hold the accept
 * key, it exercises the whole cross-node answer — index lookup, remote destination, peer frame,
 * delivery on the far side — which the receiving node then reports through
 * `test:cluster:inspect` (HIL-668).
 *
 * Database-free by contract: it talks to nothing but the local command socket.
 */
class ClusterTestClientSendCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:client:send)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_CLIENT_SEND;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Send a signal to one browser, wherever in the cluster it hangs (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:client:send

Description:
  Raise an addressed signal for one browser from this node, as an agent would. The
  routing pass looks the accept key up in the cluster connection index and forwards it to
  whichever node holds it; that node reports the delivery through test:cluster:inspect.
  Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:client:send <acceptKey> <text>

Examples:
  php cli.php test:cluster:client:send ak-node-a-1 hello
HELP;
    }

    /**
     * Sends the addressed-signal request and reports what was raised.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the accept key, then the text
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked two lines below
        $acceptKey = $args[0] ?? '';
        // external-boundary: the harness's command line, checked on the very next line
        $text = $args[1] ?? '';
        if ($acceptKey === '' || $text === '') {
            echo "Error: acceptKey and text arguments are required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(CommandConstants::COMMAND_CLUSTER_CLIENT_SEND, [
                CommandConstants::FIELD_ACCEPT_KEY => $acceptKey,
                CommandConstants::FIELD_TEXT => $text,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CommandConstants::COMMAND_CLUSTER_CLIENT_SEND);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            $detail = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        echo "Sent to {$acceptKey}\n";

        return ExitCode::SUCCESS;
    }
}
