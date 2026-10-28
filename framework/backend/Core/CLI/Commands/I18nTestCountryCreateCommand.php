<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\I18n\DTO\I18nTestCountryCreateDTO;
use Hilos\I18n\I18nTestCommandConstants;
use JsonException;

/** Create a country fixture through the live i18n library (test-only). */
class I18nTestCountryCreateCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = ['enabled'];

    private const int ARGUMENT_COUNT = 3;
    private const int CURRENCY_SYMBOL_MAX_CHARS = 8;

    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::I18N_TEST_COUNTRY_CREATE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Create a country fixture (test-only)';
    }

    /** @return string Terminal syntax and defaults */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:i18n:country:create <code> <currencySymbol> <currencyCode> [--enabled=0|1]

Description:
  Create a country through the live i18n library; an occupied address is refused.
  Flags default to 0.
  Refuses on a production-like environment.
HELP;
    }

    /**
     * @param array<string, mixed> $options Declared creation flags
     * @param list<string> $args Positional creation arguments
     * @return int Exit code
     * @throws CommandException When the command name is not test-only
     * @throws JsonException When the created row's reply cannot be encoded
     */
    protected function run(array $options, array $args): int
    {
        $code = $args[0] ?? null;
        $currencySymbol = $args[1] ?? null;
        $currencyCode = $args[2] ?? null;
        $enabled = array_key_exists('enabled', $options) ? $options['enabled'] : '0';
        if (count($args) !== self::ARGUMENT_COUNT
            || !is_string($code) || preg_match('/^[a-z]{2}$/D', $code) !== 1
            || !is_string($currencySymbol) || trim($currencySymbol) === ''
            || mb_strlen($currencySymbol) > self::CURRENCY_SYMBOL_MAX_CHARS
            || !is_string($currencyCode) || preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1
            || !in_array($enabled, ['0', '1'], true)) {
            echo "Usage: test:i18n:country:create <code> <currencySymbol> <currencyCode> [--enabled=0|1]\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand(
                $this->getName(),
                new I18nTestCountryCreateDTO($code, $currencySymbol, $currencyCode, $enabled === '1')->toArray(),
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
            echo "Command failed: the reply carries no created country\n";

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
