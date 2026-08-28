<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * CommandTestEchoCommand - exercise the async command round-trip end to end (test-only).
 *
 * Sends `test:command:echo` over the command socket channel; the daemon parks the connection,
 * routes it to {@see AbstractHilosIndexAgent}, and writes the agent's echoed reply back. It
 * proves the held-connection plus agent-reply path with no operation behind it, which is why
 * it answers on the framework-owned index agent rather than on a project's: the question
 * "does the command channel of this installation work" belongs to every project alike.
 */
final class CommandTestEchoCommand extends AbstractCommandChannelTestCommand
{
    /** @var string Echoed when the operator names no message of their own */
    private const string DEFAULT_MESSAGE = 'echo';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:command:echo)
     */
    public function getName(): string
    {
        return CliCommands::COMMAND_TEST_ECHO;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Echo a message through the daemon command channel via an agent (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()} [message]

Description:
  Sends an echo command over the command socket channel. The daemon parks the
  connection, routes the command to the Hilos index agent, and writes the agent's
  echoed reply back, exercising the full async command round-trip. Refuses on a
  production-like environment.

Arguments:
  [message]  Text to echo back (default: echo)

Usage:
  php cli.php {$this->getName()}
  php cli.php {$this->getName()} "hello"
HELP;
    }

    /**
     * Sends the echo and prints what came back.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the echo message
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: a positional argument the operator may omit; the default stands in for it
        $message = $args[0] ?? self::DEFAULT_MESSAGE;
        echo "Sending echo through the command channel...\n";

        try {
            $result = $this->sendCommand($this->getName(), [CommandConstants::FIELD_MESSAGE => $message]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            $detail = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";

            return ExitCode::ERROR;
        }

        $echoed = (string)$reply->payload[CommandConstants::FIELD_MESSAGE];
        echo "Reply (ok): {$echoed}\n";

        return ExitCode::SUCCESS;
    }
}
