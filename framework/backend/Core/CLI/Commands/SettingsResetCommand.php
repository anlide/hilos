<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Environment\Exception\EnvException;

/**
 * SettingsResetCommand - resets a setting to its catalog default via the daemon command channel (HIL-1514).
 *
 * Database-free ({@see DatabaseFreeCommand}): the row is removed by the settings library
 * ({@see SettingsLibraryAgent}), which is the single writer of the settings collection.
 */
class SettingsResetCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (settings:reset)
     */
    public function getName(): string
    {
        return CliCommands::SETTINGS_RESET;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Reset a setting to its catalog default';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()} <key>

Description:
  Reset a setting to its catalog default. The running daemon checks the catalog
  and removes the setting override row so that the default value takes effect.

Arguments:
  <key>   Setting key

Exit codes:
  0  the setting was reset to its catalog default
  1  the daemon did not answer, or refused the command
  2  the setting key argument is missing
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} example_integer
HELP;
    }

    /**
     * Declares where the work happens: the daemon resets the setting, this process asks it to.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Resets the setting through the daemon command channel.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: key
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        // external-boundary: positional argument the operator may omit; checked below
        $key = $args[0] ?? '';
        if ($key === '') {
            echo "Usage: {$this->getName()} <key>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), [
                SettingsCommandConstants::FIELD_KEY => $key,
            ]);
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

        echo "Reply (ok): {$key} is back to its catalog default\n";

        return ExitCode::SUCCESS;
    }
}
