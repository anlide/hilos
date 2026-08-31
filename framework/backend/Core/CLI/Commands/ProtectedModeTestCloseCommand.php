<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;

/**
 * ProtectedModeTestCloseCommand - close a driven verification window back into a full freeze (test-only).
 *
 * The window has two exits and until this existed the test path could only take one of them: the
 * open ({@see ProtectedModeTestOpenCommand}) lifts the freeze entirely, so nothing proved that
 * the other exit - the one an operator takes when the verifiers found something wrong - works at
 * all. What it is worth proving is everything the close does at once: every pass voided, this
 * node's agents stopped again, the maintenance screen back for the verifiers too, and the freeze
 * ready for another destructive operation.
 *
 * It cannot be {@see CliCommands::PROTECTED_MODE_CLOSE}, for the same reason
 * {@see ProtectedModeTestPassCommand} cannot be the operator's mint: a command routes to exactly
 * one agent type per project, that one belongs to the agent that runs real operations, and the
 * freeze may only be driven by the agent the row records as its initiator. Both names reach the
 * same handler ({@see ProtectedModeOperatorTrait}) and refreeze through the same request, so the
 * two exits do not differ by who asked for them.
 *
 * Not the teardown lever: this is refused outside the verification window, while the open lifts
 * from any phase, so a suite that just wants its stand back keeps calling the open. Test-only
 * ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}) and database-free: this
 * process only writes to a socket.
 */
class ProtectedModeTestCloseCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:protected-mode:close)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_TEST_CLOSE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Close a driven protected-mode verification window back into a full freeze (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $leave = CliCommands::PROTECTED_MODE_TEST_LEAVE;
        $open = CliCommands::PROTECTED_MODE_TEST_OPEN;
        $close = CliCommands::PROTECTED_MODE_CLOSE;

        return <<<HELP
Command: {$this->getName()}

Description:
  Take a driven freeze back out of the verification window and freeze it fully again,
  waiting until this node's runtime row reads active. Every pass is voided, the verifiers
  see the maintenance screen along with everyone else, this node's agents stop again, and
  another destructive operation may run.

  The counterpart of {$close}, which belongs to the agent that runs
  real operations and refuses a freeze it did not start. The close itself is the same one:
  same request, same refusals, same wait for the row to read active.

  Run it after {$leave} has opened the verification
  window. The other exit is {$open}, which opens the
  system to everyone and is the one to use for teardown - this command is refused unless
  the mode is in its verification window.

  Refuses on a production-like environment, where the operator command is the way in.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the agent to close the window back and prints the phase the row reached.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        try {
            $result = $this->sendCommand($this->getName(), []);
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
            echo "Refused: {$detail}\n";

            return ExitCode::ERROR;
        }

        $phase = (string)($reply->payload[ProtectedModeCommandConstants::FIELD_PHASE] ?? 'unknown');
        echo "Protected mode closed back, the system is frozen again (phase: {$phase})\n";

        return ExitCode::SUCCESS;
    }
}
