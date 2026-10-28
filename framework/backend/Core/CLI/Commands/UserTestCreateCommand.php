<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\PhoneNumber;
use Hilos\Auth\TestUserCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Theme\ThemeSettingsCatalog;
use JsonException;

/** Creates one fixture account through the live users library. */
class UserTestCreateCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = ['phone', 'theme'];

    /** @return string Test-only command name */
    public function getName(): string
    {
        return CliCommands::USER_TEST_CREATE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Create a verified fixture user (test-only)';
    }

    /** @return string Terminal syntax and defaults */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:user:create <email> <passwordHash|none> [--phone=<E.164>] [--theme=light|dark|system]

Description:
  Create a verified fixture account through the live users library.
  Use none for magic-link sign-in. Phone is optional; theme defaults to null.
  Refuses on a production-like environment.
HELP;
    }

    /**
     * @param array<string, mixed> $options Optional phone and theme
     * @param list<string> $args Email and precomputed hash, or none
     * @return int Exit code
     * @throws CommandException When the command name is not test-only
     * @throws JsonException When the success payload cannot be encoded
     */
    protected function run(array $options, array $args): int
    {
        $email = $args[0] ?? null;
        $hashArgument = $args[1] ?? null;
        $phone = array_key_exists('phone', $options) ? $options['phone'] : null;
        $theme = array_key_exists('theme', $options) ? $options['theme'] : null;
        if (count($args) !== 2
            || !is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false
            || !is_string($hashArgument)
            || ($hashArgument !== 'none' && password_get_info($hashArgument)['algo'] === null)
            || (array_key_exists('phone', $options)
                && (!is_string($phone) || PhoneNumber::normalize($phone) !== $phone || $phone[1] === '0'))
            || (array_key_exists('theme', $options) && !in_array($theme, ThemeSettingsCatalog::THEME_VALUES, true))) {
            echo "Usage: test:user:create <email> <passwordHash|none> [--phone=<E.164>] [--theme=light|dark|system]\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), [
                TestUserCommandConstants::FIELD_EMAIL => strtolower(trim($email)),
                TestUserCommandConstants::FIELD_PASSWORD_HASH => $hashArgument === 'none' ? null : $hashArgument,
                TestUserCommandConstants::FIELD_THEME_PICK => $theme,
                TestUserCommandConstants::FIELD_PHONE => $phone,
            ]);
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
        $userId = $result->reply->payload[TestUserCommandConstants::FIELD_USER_ID] ?? null;
        $replyEmail = $result->reply->payload[TestUserCommandConstants::FIELD_EMAIL] ?? null;
        $name = $result->reply->payload[TestUserCommandConstants::FIELD_NAME] ?? null;
        if (!is_int($userId) || $userId <= 0 || !is_string($replyEmail) || !is_string($name)) {
            echo "Command failed: the reply carries no created user\n";

            return ExitCode::ERROR;
        }

        echo json_encode([
            TestUserCommandConstants::FIELD_USER_ID => $userId,
            TestUserCommandConstants::FIELD_EMAIL => $replyEmail,
            TestUserCommandConstants::FIELD_NAME => $name,
        ], JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}
