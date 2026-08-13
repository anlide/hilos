<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;

/**
 * ProtectedModeOpenCommand - open the system to everyone, ending the verification window.
 *
 * The command that finishes a destructive operation, and the reason nothing finishes one by
 * itself any more: {@see BackupAgent::finishRestore()} used to lift the freeze unconditionally,
 * so a restore that died mid-import opened a half-loaded database on its own. It now opens the
 * verification window instead, and a human decides - having looked - whether the system may
 * come back.
 *
 * The lift is the existing one, including the "mode off" frame that forces the reload every
 * client needs after a restore, so this is not a second exit but the same one made explicit.
 * Deliberately the one command not gated on the verification window: it is the lever that gets
 * a node out of a freeze at all, and an operator holding a system stuck mid-operation must not
 * be told to reach some other phase first.
 *
 * An operator command reaching the initiator agent over the command channel
 * ({@see ProtectedModeOperatorTrait}); database-free, because the database it would otherwise
 * open is the one the operation just rewrote.
 */
class ProtectedModeOpenCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (protected-mode:open)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_OPEN;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Open the system to everyone, ending the protected-mode verification window';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $pass = CliCommands::PROTECTED_MODE_PASS;
        $close = CliCommands::PROTECTED_MODE_CLOSE;

        return <<<HELP
Command: {$this->getName()}

Description:
  Lift the protected-mode freeze for everyone and wait until this node's runtime row is
  back to inactive. Every pass minted for the window is voided, the maintenance screen
  goes away, and every client reloads - which is what a client needs after a restore.

  Nothing opens the system without this command: an operation that finishes lands in the
  verification window, where {$pass} lets a hand-picked circle in to
  confirm the system really came back. If it did not, {$close} closes
  it again instead.

  Answered only by the agent that froze the node - a freeze belongs to whoever started the
  operation - and refused when nothing is frozen here.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the initiator agent to open the system and prints the outcome.
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

        $phase = (string)($reply->payload[ProtectedModeCommandConstants::FIELD_PHASE] ?? 'unknown');
        echo "Protected mode lifted, the system is open (phase: {$phase})\n";

        return ExitCode::SUCCESS;
    }
}
