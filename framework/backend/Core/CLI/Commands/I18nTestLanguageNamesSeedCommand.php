<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/** Create the fixed names fixture through its owning library (test-only). */
final class I18nTestLanguageNamesSeedCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Create the language names fixture (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:i18n:language-names:seed

Description:
  Create the fixed qv/qw language names fixture through the live i18n library.
  Refuses on a production-like environment or when a fixture code belongs to another row.

Usage:
  php cli.php test:i18n:language-names:seed
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional arguments, which must be empty
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        if ($args !== []) {
            echo "Usage: test:i18n:language-names:seed\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), []);
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

        echo "Language names fixture created.\n";

        return ExitCode::SUCCESS;
    }
}
