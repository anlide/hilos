<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Notification\NotificationCommandConstants;
use JsonException;

/** Ask the live owner to cleanup one exact test notification. */
class NotificationTestCleanupCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** The exact recipient and title are positional. */
    public const array OPTIONS = [];

    /** @return string Test command wire name */
    public function getName(): string
    {
        return CliCommands::NOTIFICATION_TEST_CLEANUP;
    }

    /** @return string Owner operation */
    public function getDescription(): string
    {
        return 'cleanup one exact test notification through the live owner';
    }

    /** @return string Positional usage and environment restriction */
    public function getHelp(): string
    {
        return 'test:notification:cleanup <userId> <title> (non-production only)';
    }

    /**
     * @param array<string, mixed> $options Unused options
     * @param list<string> $args Recipient id and exact title
     * @return int Process exit code
     * @throws CommandException When the command is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        if (count($args) !== 2 || filter_var($args[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            echo "Usage: {$this->getName()} <userId> <title>\n";

            return ExitCode::INVALID_ARGUMENT;
        }
        try {
            $result = $this->sendCommand($this->getName(), [
                NotificationCommandConstants::FIELD_USER_ID => (int)$args[0],
                NotificationCommandConstants::FIELD_TITLE => $args[1],
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
        try {
            echo json_encode($result->reply->payload, JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException) {
            echo "Command failed: invalid notification reply\n";

            return ExitCode::ERROR;
        }

        return ExitCode::SUCCESS;
    }
}
