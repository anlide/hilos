<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\ProtectedMode\ProtectedModeReAskVerdict;

/**
 * ProtectedModePassCommand - mint one pass into the verification window and print it.
 *
 * The single place a pass ever exists outside the operator's terminal: the agent mints it, the
 * freeze row keeps only its hash, and this line of output is what the operator hands to a
 * verifier. Calling it again mints another, so the size of the circle is the operator's business
 * rather than a configured number, and every pass dies with the window.
 *
 * An operator command, not a test fixture - it runs on production, which is the only place a
 * real restore ends. It reaches the initiator agent over the command channel
 * ({@see ProtectedModeOperatorTrait}) because the freeze may only be driven by the agent the row
 * records as having started it; the CLI process cannot touch the mode at all.
 *
 * Database-free: this process only writes to a socket - and it must be, because the database it
 * would otherwise open is the one the operation being verified just rewrote.
 *
 * A lost mint reply is never recovered as success: minting moves no phase, and the clear pass
 * existed only in that reply. Its orphaned hash admits nobody, and closing the verification
 * window voids it with every other pass hash.
 */
class ProtectedModePassCommand implements CommandInterface, DatabaseFreeCommand
{
    use ProtectedModeReAskTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (protected-mode:pass)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_PASS;
    }

    /**
     * Declares the rule: the daemon does the work and this process only initiates it and prints.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Mint one pass into the protected-mode verification window and print it';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $open = CliCommands::PROTECTED_MODE_OPEN;
        $close = CliCommands::PROTECTED_MODE_CLOSE;

        return <<<HELP
Command: {$this->getName()}

Description:
  Mint one pass into the verification window a finished operation left the system in, and
  print it. Give it to a verifier: they enter it on the maintenance screen, and their
  window alone is let through the lockdown while everyone else keeps seeing the screen.
  Run it again for every further verifier - there is no configured circle size, and no
  per-pass revoke, because leaving the window in either direction voids every pass at once.

  Only the hash of the pass is ever stored, so this output cannot be recovered afterwards.
  A lost pass is replaced by minting another.

  Refused unless the mode is in its verification window, and answered only by the agent
  that froze the node - a freeze belongs to whoever started the operation.

Exits from the window:
  {$open}   open the system to everyone
  {$close}  close it back, so another attempt may run

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the initiator agent for a pass and prints the clear key it minted.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        try {
            $result = $this->sendCommand($this->getName(), []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            try {
                $outcome = $this->reAskProtectedMode($this->getName(), null, []);
            } catch (EnvException $e) {
                echo "Error: {$e->getMessage()}\n";

                return ExitCode::CONFIG_ERROR;
            }

            $seconds = (int)CommandChannelWindows::CALLER_WAIT_SECONDS;
            if ($outcome->verdict === ProtectedModeReAskVerdict::UNKNOWN) {
                return $this->printToStandardError(
                    "The daemon did not answer {$outcome->driveCommand} within {$seconds}s, and did not answer "
                        . CliCommands::PROTECTED_MODE_INSPECT
                        . ' either; whether a pass was minted is unknown',
                );
            }

            $passCount = $outcome->snapshot[ProtectedModeCommandConstants::FIELD_PASS_COUNT] ?? null;
            if (!is_int($passCount)) {
                return $this->printToStandardError(
                    "The daemon did not answer {$outcome->driveCommand} within {$seconds}s;"
                        . ' the state reply carried no pass count, so whether a pass was minted is unknown',
                );
            }

            return $this->printToStandardError(
                "The daemon did not answer {$outcome->driveCommand} within {$seconds}s;"
                    . " the node now holds {$passCount} passes, but a pass exists only in the reply that was lost"
                    . ' - mint another with ' . CliCommands::PROTECTED_MODE_PASS,
            );
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        // external-boundary: a reply off the command channel, checked on the very next line
        $pass = $reply->payload[ProtectedModeCommandConstants::FIELD_PASS] ?? null;
        if (!is_string($pass) || $pass === '') {
            // The agent answers ok only once the hash is on the row, so a missing key here is a
            // broken reply rather than a refusal - and printing nothing would read as success.
            echo "The agent recorded a pass but returned no key\n";

            return ExitCode::ERROR;
        }

        echo "Pass: {$pass}\n";
        echo "Give it to a verifier: they enter it on the maintenance screen.\n";

        return ExitCode::SUCCESS;
    }
}
