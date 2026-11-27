<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\I18n\I18nTestCommandConstants;

/** Create and enable a built-in locale through the live i18n library (test-only). */
final class I18nTestLocaleOnCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::I18N_TEST_LOCALE_ON;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Create and enable a built-in locale (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:i18n:locale:on <code>

Description:
  Create and enable a built-in locale through the live i18n library.
  Refuses on a production-like environment.

Arguments:
  <code>  A language code (es) or a language-COUNTRY pair (es-ES) from the built-in catalog

Usage:
  php cli.php test:i18n:locale:on es
  php cli.php test:i18n:locale:on es-ES
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional arguments: one locale code
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $code = $args[0] ?? null;
        if (!is_string($code) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $code) !== 1 || count($args) !== 1) {
            echo "Usage: test:i18n:locale:on <code>\n";

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
        $enabled = $result->reply->payload[I18nTestCommandConstants::FIELD_ENABLED] ?? null;
        if (!is_string($replyCode) || $replyCode !== $code || $enabled !== true) {
            echo "Command failed: the reply carries no enabled locale\n";

            return ExitCode::ERROR;
        }

        echo "Locale {$replyCode} enabled\n";

        return ExitCode::SUCCESS;
    }
}
