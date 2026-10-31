<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\CLI\DTO\SendPauseEndCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;

/** Asks the Chat agent to end one person's message send pause through the test-only command channel. */
final class SendPauseTestEndCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = [self::OPTION_USER_ID];

    private const string OPTION_USER_ID = 'user-id';

    /** @return string Test-only command name */
    public function getName(): string
    {
        return ChatCommandConstants::SEND_PAUSE_TEST_END;
    }

    /** @return string Help listing description */
    public function getDescription(): string
    {
        return 'End the message send pause of one Chat user (test-only)';
    }

    /** @return string Required flags and production refusal */
    public function getHelp(): string
    {
        return 'Usage: php cli.php test:chat:end-send-pause --user-id=<userId>'
            . "\nRefuses on a production-like environment.\n";
    }

    /**
     * @param array<string, mixed> $options Required person coordinate
     * @param list<string> $args No positional arguments
     * @return int Exit code
     * @throws CommandException When the command name is not test-only
     * @throws InvalidFormatException When the coordinate cannot form a command payload
     */
    protected function run(array $options, array $args): int
    {
        $userId = $options[self::OPTION_USER_ID] ?? null;
        if ($args !== [] || !is_string($userId) || !ctype_digit($userId) || (int)$userId <= 0) {
            echo $this->getHelp();

            return ExitCode::INVALID_ARGUMENT;
        }
        try {
            $result = $this->sendCommand($this->getName(), new SendPauseEndCommandData((int)$userId)->toArray());
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
        $endedFor = $result->reply->payload[ChatCommandConstants::userId] ?? null;
        if (!is_int($endedFor)) {
            echo "Command failed: the reply carries no user id\n";

            return ExitCode::ERROR;
        }
        echo "Ended the send pause for user {$endedFor}\n";

        return ExitCode::SUCCESS;
    }
}
