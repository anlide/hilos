<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Environment\Exception\EnvException;

/**
 * SettingsSetCommand - sets a setting value via the daemon command channel (HIL-1514).
 *
 * Database-free ({@see DatabaseFreeCommand}): the row is written by the settings library
 * ({@see SettingsLibraryAgent}), which is the single writer of the settings collection.
 */
class SettingsSetCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (settings:set)
     */
    public function getName(): string
    {
        return CliCommands::SETTINGS_SET;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Set a setting value';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()} <key> <value>

Description:
  Set a setting value. The running daemon checks the catalog, validates the value
  type and key rules, and writes the setting row.

Arguments:
  <key>     Setting key
  <value>   Setting value (string, can be empty in quotes)

Exit codes:
  0  the setting value was written
  1  the daemon did not answer, or refused the command
  2  required arguments are missing
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} example_integer 30
HELP;
    }

    /**
     * Declares where the work happens: the daemon writes the setting, this process asks it to.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Sets the setting value through the daemon command channel.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: key and value
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        // external-boundary: positional arguments the operator may omit; checked below
        $key = $args[0] ?? '';
        if ($key === '' || !array_key_exists(1, $args)) {
            echo "Usage: {$this->getName()} <key> <value>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $value = (string)$args[1];

        try {
            $result = $this->sendCommand($this->getName(), [
                SettingsCommandConstants::FIELD_KEY => $key,
                SettingsCommandConstants::FIELD_VALUE => $value,
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

        echo "Reply (ok): {$key} = {$value}\n";

        return ExitCode::SUCCESS;
    }
}
