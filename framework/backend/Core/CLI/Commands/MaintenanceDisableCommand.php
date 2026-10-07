<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\ProtectedMode\ProtectedModeReAskVerdict;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * MaintenanceDisableCommand - open the system again from the manual maintenance window.
 *
 * An operator command that lifts manual maintenance and opens the system to everyone.
 *
 * Answered by {@see AbstractHilosIndexAgent} over the command channel; database-free,
 * because the CLI process only sends a request to the daemon.
 */
class MaintenanceDisableCommand implements CommandInterface, DatabaseFreeCommand
{
    use ProtectedModeReAskTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (maintenance:disable)
     */
    public function getName(): string
    {
        return CliCommands::MAINTENANCE_DISABLE;
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
        return 'Lift manual maintenance and open the system to everyone again';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $enable = CliCommands::MAINTENANCE_ENABLE;

        return <<<HELP
Command: {$this->getName()}

Description:
  Lift manual maintenance and open the system to everyone again.
  The maintenance screen goes away and normal traffic is admitted.

  Answered by the index agent (HilosIndexAgent). Neighboring commands:
  {$enable} closes the system to visitors;
  maintenance:pass mints a pass into the maintenance window.

  Pass codes are stored only as hashes and printed exactly once when minted.
  Entering manual maintenance on a cluster is currently refused (HIL-1367).
  Database restore uses its own protected-mode:* operator ladder instead.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the index agent to disable manual maintenance and prints the outcome.
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
                $outcome = $this->reAskProtectedMode(
                    $this->getName(),
                    null,
                    [ProtectedModeRuntime::PHASE_INACTIVE],
                );
            } catch (EnvException $e) {
                echo "Error: {$e->getMessage()}\n";

                return ExitCode::CONFIG_ERROR;
            }

            $seconds = (int)CommandChannelWindows::CALLER_WAIT_SECONDS;
            if ($outcome->verdict === ProtectedModeReAskVerdict::UNKNOWN) {
                return $this->printToStandardError(
                    "The daemon did not answer {$outcome->driveCommand} within {$seconds}s, and did not answer "
                        . CliCommands::PROTECTED_MODE_INSPECT
                        . ' either; whether the system opened is unknown',
                );
            }

            $phase = $outcome->snapshot[ProtectedModeCommandConstants::FIELD_PHASE] ?? null;
            $phaseText = is_string($phase) ? $phase : 'unknown';
            if ($outcome->verdict !== ProtectedModeReAskVerdict::TAKEN) {
                return $this->printToStandardError(
                    "The daemon did not answer {$outcome->driveCommand} within {$seconds}s;"
                        . " the node reads phase '{$phaseText}', so the system is NOT open",
                );
            }

            $this->writeToStandardError(
                "The daemon did not answer {$outcome->driveCommand} within {$seconds}s;"
                    . ' asked the node for its state instead',
            );
            echo "Manual maintenance is off, the system is open (phase: {$phaseText})\n";

            return ExitCode::SUCCESS;
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $phase = (string)($reply->payload[ProtectedModeCommandConstants::FIELD_PHASE] ?? 'unknown');
        echo "Manual maintenance is off, the system is open (phase: {$phase})\n";

        return ExitCode::SUCCESS;
    }
}
