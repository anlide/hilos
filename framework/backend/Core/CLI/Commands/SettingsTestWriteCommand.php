<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Environment\Exception\EnvException;

/**
 * Asks the live settings library to write a batch of example settings keys, one write per pair (test-only, HIL-1856).
 *
 * The CLI half only: each argument is split at its first "=" into a key and a value, the pairs
 * travel in their order, and the reply is printed one accepted pair per line. Which keys are let
 * in, how many pairs a batch may carry and why a write was refused are the library's to say
 * ({@see SettingsLibraryAgent}), because it is the one writer of settings.
 */
class SettingsTestWriteCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::SETTINGS_TEST_WRITE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Write example settings keys through the settings library, one write per pair, in order (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:settings:write <key>=<value> [<key>=<value> ...]

Description:
  Write example settings keys through the running settings library, one write
  per pair and in the order given, the way the settings screen writes them.
  Each argument is split at its first "=", so a value may itself carry "=".
  The first refused write stops the batch; the pairs written before it stay.
  Refuses on a production-like environment.

Arguments:
  <key>=<value>  Example settings key and the value to write under it

Exit codes:
  0  every pair was written
  1  the daemon did not answer, refused a pair, or answered without the writes
  2  no pair was given, or an argument carries no key before its "="
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php test:settings:write example_string=delivery-1 example_integer=2
HELP;
    }

    /**
     * Sends the pairs to the settings library and prints every pair it accepted.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Key and value pairs, each written as key=value
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $writes = $this->writesOf($args);
        if ($writes === null) {
            echo "Usage: test:settings:write <key>=<value> [<key>=<value> ...]\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), [SettingsCommandConstants::FIELD_WRITES => $writes]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }

        $accepted = $result->reply->payload[SettingsCommandConstants::FIELD_WRITES] ?? null;
        if (!is_array($accepted) || $accepted === [] || !array_is_list($accepted)) {
            echo "Command failed: the reply carries no accepted writes\n";

            return ExitCode::ERROR;
        }

        // Read whole before anything is printed, so a reply broken halfway prints no half of itself.
        $lines = [];
        foreach ($accepted as $write) {
            if (
                !is_array($write)
                || !is_string($write[SettingsCommandConstants::FIELD_KEY] ?? null)
                || !is_string($write[SettingsCommandConstants::FIELD_VALUE] ?? null)
                || !is_int($write[SettingsCommandConstants::FIELD_ACCEPTED_AT] ?? null)
            ) {
                echo "Command failed: the reply carries a write without its key, value or acceptance moment\n";

                return ExitCode::ERROR;
            }
            $lines[] = "Wrote {$write[SettingsCommandConstants::FIELD_KEY]} = {$write[SettingsCommandConstants::FIELD_VALUE]}"
                . ", accepted at epoch ms {$write[SettingsCommandConstants::FIELD_ACCEPTED_AT]}\n";
        }

        echo implode('', $lines);

        return ExitCode::SUCCESS;
    }

    /**
     * Splits every argument at its first "=" into the pair the library reads.
     *
     * Only the shape is judged here - a pair at all, and a key before its "=". An empty value is a
     * value; whether the key may be written is the library's question.
     *
     * @param list<string> $args Arguments as the operator typed them
     * @return ?list<array<string, string>> Pairs in the order given, or null when there is none or one has no key
     */
    private function writesOf(array $args): ?array
    {
        if ($args === []) {
            return null;
        }

        $writes = [];
        foreach ($args as $arg) {
            $separator = strpos($arg, '=');
            if ($separator === false || $separator === 0) {
                return null;
            }
            $writes[] = [
                SettingsCommandConstants::FIELD_KEY => substr($arg, 0, $separator),
                SettingsCommandConstants::FIELD_VALUE => substr($arg, $separator + 1),
            ];
        }

        return $writes;
    }
}
