<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;

/**
 * ProtectedModeTestLeaveCommand - end the driven operation into the verification window (test-only).
 *
 * The mirror of {@see ProtectedModeTestEnterCommand}, and authorized the same way production
 * authorizes a release: the agent answers only if the runtime row names it as the initiator, so
 * there is no forced lift here and none is wanted. A stand does not strand on that - the
 * initiator is a long-lived agent, so the open that follows a failed test arrives from the same
 * agent and passes. A freeze whose initiator really is gone belongs to the watchdog (HIL-482),
 * not to a lever in a test command.
 *
 * **It no longer opens the system**, because nothing does that by finishing its own operation
 * any more: this lands in the verification window exactly where a finished restore lands, and
 * {@see ProtectedModeTestOpenCommand} is the explicit open - the same two steps production takes.
 *
 * Returns once the local runtime row reads verifying. A refusal comes back as its reason,
 * because the core drops an unauthorized request, or one made against the wrong phase, with a
 * warning and replies to nobody.
 *
 * Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}) and
 * database-free: this process only writes to a socket.
 */
class ProtectedModeTestLeaveCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:protected-mode:leave)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_TEST_LEAVE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'End the driven operation into the protected-mode verification window (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $open = CliCommands::PROTECTED_MODE_TEST_OPEN;

        return <<<HELP
Command: {$this->getName()}

Description:
  Tell the initiator agent that the driven operation is over, and wait until this node's
  runtime row reads verifying. The system stays closed to everyone: opening it is
  {$open}, exactly as it is a separate operator command in
  production. Replies with the phase the agent observed, or with the reason it refused -
  nothing frozen here, the mode not being active, or this agent not being the initiator the
  freeze recorded. Authorization is by initiator identity, exactly as in production.
  Refuses on a production-like environment.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the agent to end its operation into the verification window and prints the outcome.
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
        echo "Operation ended, the verification window is open (phase: {$phase})\n";

        return ExitCode::SUCCESS;
    }
}
