<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\CLI\DTO\BotAnswerNowCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;

/** Asks the chat library to have one active bot answer now through the test-only command channel. */
final class BotTestAnswerNowCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = [self::OPTION_BOT_ID];

    private const string OPTION_BOT_ID = 'bot-id';

    /** @return string Test-only command name */
    public function getName(): string
    {
        return ChatCommandConstants::BOT_TEST_ANSWER_NOW;
    }

    /** @return string Help listing description */
    public function getDescription(): string
    {
        return 'Have one active Chat bot answer now, past its delay, chance, pause and topic (test-only)';
    }

    /** @return string Required flags and production refusal */
    public function getHelp(): string
    {
        return 'Usage: php cli.php test:chat:bot-answer-now --bot-id=<botId>'
            . "\nRefuses on a production-like environment.\n";
    }

    /**
     * @param array<string, mixed> $options Required bot coordinate
     * @param list<string> $args No positional arguments
     * @return int Exit code
     * @throws CommandException When the command name is not test-only
     * @throws InvalidFormatException When the coordinate cannot form a command payload
     */
    protected function run(array $options, array $args): int
    {
        $botId = $options[self::OPTION_BOT_ID] ?? null;
        if ($args !== [] || !is_string($botId) || !ctype_digit($botId) || (int)$botId <= 0) {
            echo $this->getHelp();

            return ExitCode::INVALID_ARGUMENT;
        }
        try {
            $result = $this->sendCommand($this->getName(), new BotAnswerNowCommandData((int)$botId)->toArray());
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
        $answering = $result->reply->payload[ChatCommandConstants::botId] ?? null;
        if (!is_int($answering)) {
            echo "Command failed: the reply carries no bot id\n";

            return ExitCode::ERROR;
        }
        echo "Bot {$answering} answers now\n";

        return ExitCode::SUCCESS;
    }
}
