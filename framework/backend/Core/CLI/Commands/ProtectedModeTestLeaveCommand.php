<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;

/**
 * ProtectedModeTestLeaveCommand - lift the protected-mode freeze through its initiator (test-only).
 *
 * The mirror of {@see ProtectedModeTestEnterCommand}, and authorized the same way production
 * authorizes a release: the agent answers only if the runtime row names it as the initiator, so
 * there is no forced lift here and none is wanted. A stand does not strand on that - the
 * initiator is a long-lived agent, so the leave that follows a failed test arrives from the same
 * agent and passes. A freeze whose initiator really is gone belongs to the watchdog (HIL-482),
 * not to a lever in a test command.
 *
 * Returns once the local runtime row is back to inactive, so a caller may load a page on the next
 * line without racing the agents coming back up. A refusal comes back as its reason, because the
 * core drops an unauthorized or redundant disable with a warning and replies to nobody.
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
        return 'Leave protected mode through the initiator agent that entered it (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()}

Description:
  Ask the initiator agent to lift the protected-mode freeze, and wait until this node's
  runtime row is back to inactive. Replies with the phase the agent observed, or with the
  reason it refused - the mode being inactive already, or this agent not being the
  initiator the freeze recorded. There is no forced lift: authorization is by initiator
  identity, exactly as in production. Refuses on a production-like environment.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the agent to leave protected mode and prints the outcome.
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
        echo "Protected mode left (phase: {$phase})\n";

        return ExitCode::SUCCESS;
    }
}
