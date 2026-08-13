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
 */
class ProtectedModePassCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

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
