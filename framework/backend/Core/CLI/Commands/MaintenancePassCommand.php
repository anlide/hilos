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

/**
 * MaintenancePassCommand - mint one pass into the manual maintenance window and print it.
 *
 * An operator command that mints a one-time pass code so a person can access the system
 * while manual maintenance mode is enabled.
 *
 * Answered by {@see AbstractHilosIndexAgent} over the command channel; database-free,
 * because the CLI process only sends a request to the daemon.
 */
class MaintenancePassCommand implements CommandInterface, DatabaseFreeCommand
{
    use ProtectedModeReAskTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (maintenance:pass)
     */
    public function getName(): string
    {
        return CliCommands::MAINTENANCE_PASS;
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
        return 'Mint one pass into the manual maintenance window and print it';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $enable = CliCommands::MAINTENANCE_ENABLE;
        $disable = CliCommands::MAINTENANCE_DISABLE;

        return <<<HELP
Command: {$this->getName()}

Description:
  Mint one pass into the manual maintenance window and print it.
  Give it to the person to let in: they enter it on the maintenance screen.

  Answered by the index agent (HilosIndexAgent). Neighboring commands:
  {$enable} closes the system to visitors;
  {$disable} lifts manual maintenance and opens the system to everyone again.

  Pass codes are stored only as hashes and printed exactly once when minted.
  Entering manual maintenance on a cluster is currently refused (HIL-1367).
  Database restore uses its own protected-mode:* operator ladder instead.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the index agent for a pass and prints the clear key it minted.
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
                    . ' - mint another with ' . CliCommands::MAINTENANCE_PASS,
            );
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $pass = $reply->payload[ProtectedModeCommandConstants::FIELD_PASS] ?? null;
        if (!is_string($pass) || $pass === '') {
            echo "The agent recorded a pass but returned no key\n";

            return ExitCode::ERROR;
        }

        echo "Pass: {$pass}\n";
        echo "Give it to the person to let in: they enter it on the maintenance screen.\n";

        return ExitCode::SUCCESS;
    }
}
