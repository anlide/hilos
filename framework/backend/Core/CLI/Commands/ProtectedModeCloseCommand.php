<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;

/**
 * ProtectedModeCloseCommand - close the system back from the verification window.
 *
 * The other exit, and the reason an operator who has just seen broken data is not forced to open
 * the system to real users in order to do anything about it: the freeze returns to full, this
 * node's agents are stopped again, every pass is voided, the maintenance screen comes back for
 * the verifiers too, and another destructive operation may run.
 *
 * An operator command reaching the initiator agent over the command channel
 * ({@see ProtectedModeOperatorTrait}); database-free, because the database it would otherwise
 * open is the one the operation just rewrote.
 */
class ProtectedModeCloseCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (protected-mode:close)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_CLOSE;
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
        return 'Close the system again from the protected-mode verification window';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $open = CliCommands::PROTECTED_MODE_OPEN;

        return <<<HELP
Command: {$this->getName()}

Description:
  Take the system back out of the verification window and freeze it fully again, waiting
  until this node's runtime row reads active. Every pass is voided, the verifiers see the
  maintenance screen along with everyone else, this node's agents stop again, and another
  destructive operation may run.

  The exit to take when the verifiers found something wrong. The other one is
  {$open}, which opens the system to everyone.

  Refused unless the mode is in its verification window, and answered only by the agent
  that froze the node.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the initiator agent to close the system back and prints the outcome.
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
            return $this->printChannelFailure($result, $this->getName());
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $phase = (string)($reply->payload[ProtectedModeCommandConstants::FIELD_PHASE] ?? 'unknown');
        echo "Protected mode closed back, the system is frozen again (phase: {$phase})\n";

        return ExitCode::SUCCESS;
    }
}
