<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use Hilos\I18n\DTO\I18nTestLocaleCreateDTO;
use Hilos\I18n\I18nTestCommandConstants;
use JsonException;

/** Create a locale fixture through the live i18n library (test-only). */
class I18nTestLocaleCreateCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = ['country-id', 'enabled'];

    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::I18N_TEST_LOCALE_CREATE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Create a locale fixture (test-only)';
    }

    /** @return string Terminal syntax and defaults */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:i18n:locale:create <languageId> [--country-id=N] [--enabled=0|1]

Description:
  Create a locale through the live i18n library; an occupied address is refused.
  Flags default to 0; an omitted country-id means no country.
  Refuses on a production-like environment.
HELP;
    }

    /**
     * @param array<string, mixed> $options Declared creation flags
     * @param list<string> $args Positional creation arguments
     * @return int Exit code
     * @throws CommandException When the command name is not test-only
     * @throws JsonException When the created row's reply cannot be encoded
     * @throws InvalidFormatException When a locale parent ID is not positive
     */
    protected function run(array $options, array $args): int
    {
        $languageId = $args[0] ?? null;
        $countryId = $options['country-id'] ?? null;
        $enabled = array_key_exists('enabled', $options) ? $options['enabled'] : '0';
        if (count($args) !== 1
            || !is_string($languageId) || preg_match('/^[1-9][0-9]*$/D', $languageId) !== 1
            || filter_var($languageId, FILTER_VALIDATE_INT) === false
            || (array_key_exists('country-id', $options) && (!is_string($countryId)
                || preg_match('/^[1-9][0-9]*$/D', $countryId) !== 1
                || filter_var($countryId, FILTER_VALIDATE_INT) === false))
            || !in_array($enabled, ['0', '1'], true)) {
            echo "Usage: test:i18n:locale:create <languageId> [--country-id=N] [--enabled=0|1]\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand(
                $this->getName(),
                new I18nTestLocaleCreateDTO((int)$languageId, $countryId === null ? null : (int)$countryId, $enabled === '1')->toArray(),
            );
        } catch (EnvException $failure) {
            echo "Error: {$failure->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }
        $id = $result->reply->payload[I18nTestCommandConstants::FIELD_ID] ?? null;
        $replyCode = $result->reply->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
        $replyEnabled = $result->reply->payload[I18nTestCommandConstants::FIELD_ENABLED] ?? null;
        if (!is_int($id) || $id <= 0 || !is_string($replyCode) || !is_bool($replyEnabled)) {
            echo "Command failed: the reply carries no created locale\n";

            return ExitCode::ERROR;
        }

        echo json_encode([
            I18nTestCommandConstants::FIELD_ID => $id,
            I18nTestCommandConstants::FIELD_CODE => $replyCode,
            I18nTestCommandConstants::FIELD_ENABLED => $replyEnabled,
        ], JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}
