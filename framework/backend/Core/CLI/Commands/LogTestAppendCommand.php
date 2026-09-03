<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Log\LogCommandConstants;
use Hilos\Log\LogStoreAgent;

/**
 * LogTestAppendCommand - append lines to this node's own log through the live daemon (test-only).
 *
 * Sends `test:log:append` over the command socket channel; the daemon parks the connection and
 * routes it to {@see LogStoreAgent}, which logs the asked-for lines the way it logs any of its
 * own and answers with how many it wrote. The point is the writer: a caller watching the log
 * viewer needs a line that travelled the real path - agent prints it, master files it under the
 * agent's own log - and a line appended to the file from this process would prove only that the
 * file grew.
 *
 * Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}) on both sides,
 * and database-free: everything this command causes is written by the agent, so the CLI process
 * needs no connection of its own.
 */
final class LogTestAppendCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @var string Line count used when the operator names none */
    private const string DEFAULT_COUNT = '1';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:log:append)
     */
    public function getName(): string
    {
        return CliCommands::LOG_TEST_APPEND;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Append lines to this node\'s log through the live daemon (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()} <message> [count]

Description:
  Asks the running daemon's log-store agent to write lines into its own log, so a
  follower on the log viewer sees a line that took the real path: the agent prints
  it and the master files it. Each line is numbered, so lines of one call can be
  told apart from each other and from an earlier call carrying the same message.
  Refuses on a production-like environment.

Arguments:
  <message>  Text of every appended line, numbered as "<message> #<n>"
  [count]    How many lines to write (default: 1)

Usage:
  php cli.php {$this->getName()} "probe"
  php cli.php {$this->getName()} "probe" 80
HELP;
    }

    /**
     * Validates the arguments, sends the append command, and prints what the agent wrote.
     *
     * The upper bound on the count is not repeated here: the agent owns it, because it is the
     * process that pays for a long run, and a second copy of the number would be a second
     * owner of it.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] message, [1] line count
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the very next line
        $message = $args[0] ?? '';
        if ($message === '') {
            echo "Usage: {$this->getName()} <message> [count]  (message: non-empty text)\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        // external-boundary: the operator's command line, checked on the very next line
        $countArg = $args[1] ?? self::DEFAULT_COUNT;
        if (preg_match('/^\d+$/', $countArg) !== 1 || (int)$countArg <= 0) {
            echo "Argument [count] must be a positive integer.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), [
                CommandConstants::FIELD_MESSAGE => $message,
                LogCommandConstants::FIELD_COUNT => (int)$countArg,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $written = (int)$reply->payload[LogCommandConstants::FIELD_COUNT];
        echo "Appended {$written} line(s) to the log-store agent's own log\n";

        return ExitCode::SUCCESS;
    }
}
