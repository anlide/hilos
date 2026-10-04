<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;

/**
 * ProtectedModeTestEnterCommand - take the cluster into protected mode through a live agent (test-only).
 *
 * The freeze has exactly one entry and this command does not add a second: it asks an
 * initiator agent over the command channel to call the same
 * {@see AbstractAgent::requestProtectedModeEnable()} a restore calls, the way
 * `backup:restore-request` reaches BackupAgent. Nothing here forces the runtime row. That is
 * not ceremony - the initiator identity recorded by that request is what authorizes the later
 * release and what the agent-start gate lets through, so a synthetic entry would exercise a
 * path production does not have and prove nothing about the one it does.
 *
 * The reply is a verdict, not an acknowledgement: it returns once the freeze actually took
 * hold (the agent answers from its ready hook), so a test can act on the next line instead of
 * polling {@see ProtectedModeInspectCommand}. A refusal comes back as its reason - the
 * agent pre-checks its own runtime row and answers, because the core drops a repeat enable
 * with a warning and replies to nobody, which would otherwise reach the caller as a mute
 * timeout.
 *
 * Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}, and the
 * agent repeats the refusal on its side because the command socket authenticates nobody) and
 * database-free: this process only writes to a socket.
 */
class ProtectedModeTestEnterCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:protected-mode:enter)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_TEST_ENTER;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Enter protected mode through the live initiator agent (test-only)';
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
Command: {$this->getName()} <operation> [--accept-key=<k>] [--session-token=<t>]

Description:
  Ask the live initiator agent to take this installation into protected mode for the
  named operation, and wait until the freeze has actually taken hold. Replies with the
  phase the agent observed, or with the reason it refused. Leaving the mode is
  {$leave}, authorized against the same agent. Refuses on a production-like
  environment.

Arguments:
  <operation>  Operation name the freeze protects, e.g. restore

Options:
  --accept-key=<k>  Connection accept key that stays live through the lockdown
                    (default: none, so every browser connection is locked out)
  --session-token=<t>  Session cookie of the browser that asked for the freeze
                       (default: none, so no browser is named as the operator)

Usage:
  php cli.php {$this->getName()} restore
  php cli.php {$this->getName()} restore --accept-key=abc123
  php cli.php {$this->getName()} restore --session-token=abc123
HELP;
    }

    /**
     * Validates the operation name, asks the agent to enter, and prints the outcome.
     *
     * @param array<string, mixed> $options Parsed options: --accept-key and --session-token
     * @param list<string> $args Positional args: [0] operation name
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the very next line
        $operation = $args[0] ?? '';
        if ($operation === '') {
            echo "Usage: {$this->getName()} <operation> [--accept-key=<k>] [--session-token=<t>]  (operation: non-empty name)\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        // Empty by default, exactly as BackupAgent enters for a CLI initiator: a freeze asked
        // for from a terminal has no browser connection to name. A key or session cookie is
        // passed only when the test is about the initiator's own window.
        $acceptKey = '';
        if (isset($options['accept-key'])) {
            $acceptKey = $options['accept-key'];
            if (!is_string($acceptKey) || $acceptKey === '') {
                echo "Option --accept-key must be a non-empty connection accept key.\n";

                return ExitCode::INVALID_ARGUMENT;
            }
        }

        $sessionToken = null;
        if (isset($options['session-token'])) {
            $sessionToken = $options['session-token'];
            if (!is_string($sessionToken) || $sessionToken === '') {
                echo "Option --session-token must be a non-empty session token.\n";

                return ExitCode::INVALID_ARGUMENT;
            }
        }

        try {
            $payload = [
                ProtectedModeCommandConstants::FIELD_OPERATION => $operation,
                CommandConstants::FIELD_ACCEPT_KEY => $acceptKey,
            ];
            if ($sessionToken !== null) {
                $payload[ProtectedModeCommandConstants::FIELD_SESSION_TOKEN] = $sessionToken;
            }
            $result = $this->sendCommand($this->getName(), $payload);
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

        $phase = (string)($reply->payload[ProtectedModeCommandConstants::FIELD_PHASE] ?? 'unknown');
        echo "Protected mode entered for {$operation} (phase: {$phase})\n";

        return ExitCode::SUCCESS;
    }
}
