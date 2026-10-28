<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\I18n\I18nTestCommandConstants;
use JsonException;

/** Clear a country fixture through the live i18n library (test-only). */
class I18nTestCountryClearCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::I18N_TEST_COUNTRY_CLEAR;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Clear a country fixture (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:i18n:country:clear <code>

Description:
  Clear a country fixture through the live i18n library.
  Refuses on a production-like environment.

Arguments:
  <code>  Exact custom country code; built-in countries are refused

Usage:
  php cli.php test:i18n:country:clear qx
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional arguments: one country code
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     * @throws JsonException When the cleared code cannot be encoded
     */
    protected function run(array $options, array $args): int
    {
        $code = $args[0] ?? null;
        if (!is_string($code) || preg_match('/^[a-z]{2}$/D', $code) !== 1 || count($args) !== 1) {
            echo "Usage: test:i18n:country:clear <code>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), [I18nTestCommandConstants::FIELD_CODE => $code]);
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

        $replyCode = $result->reply->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
        if (!is_string($replyCode) || $replyCode !== $code) {
            echo "Command failed: the reply carries no cleared country code\n";

            return ExitCode::ERROR;
        }

        echo json_encode([I18nTestCommandConstants::FIELD_CODE => $replyCode], JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}
