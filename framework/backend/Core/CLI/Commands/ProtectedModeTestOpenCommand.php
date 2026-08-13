<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;

/**
 * ProtectedModeTestOpenCommand - open the system to everyone from a driven freeze (test-only).
 *
 * The test path's half of the explicit open production gained: `test:protected-mode:leave` now
 * ends the driven operation in the verification window, exactly where a real one ends, so
 * something has to open the system afterwards - and the run's teardown must be able to, from
 * whatever phase it finds.
 *
 * It cannot be {@see CliCommands::PROTECTED_MODE_OPEN}. A command routes to exactly one agent
 * type per project, the operator one belongs to the agent that runs real operations
 * ({@see ProtectedModeOperatorTrait}), and a freeze may only be driven by the agent the row
 * records as its initiator - which on the driven path is this driver's carrier. Same lift, same
 * authorization, different owner.
 *
 * Returns once this node's row is back to inactive, so a caller may load a page on the next line
 * without racing the agents coming back up. Test-only ({@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}) and database-free: this process only writes to a
 * socket.
 */
class ProtectedModeTestOpenCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:protected-mode:open)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_TEST_OPEN;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Open the system to everyone from a driven protected-mode freeze (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $leave = CliCommands::PROTECTED_MODE_TEST_LEAVE;

        return <<<HELP
Command: {$this->getName()}

Description:
  Ask the initiator agent to lift a driven freeze for everyone, and wait until this node's
  runtime row is back to inactive. The counterpart of {$leave},
  which ends the driven operation in the verification window rather than opening the
  system - the same two steps production takes.

  Authorized by initiator identity, exactly as in production: there is no forced lift.
  Refuses on a production-like environment, where the operator commands are the way in.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the agent to open the system and prints the outcome.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     */
    protected function run(array $options, array $args): int
    {
        try {
            $reply = $this->sendCommand($this->getName(), []);
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
            echo "Refused: {$detail}\n";

            return ExitCode::ERROR;
        }

        $phase = (string)($reply->payload[ProtectedModeCommandConstants::FIELD_PHASE] ?? 'unknown');
        echo "Protected mode opened (phase: {$phase})\n";

        return ExitCode::SUCCESS;
    }
}
