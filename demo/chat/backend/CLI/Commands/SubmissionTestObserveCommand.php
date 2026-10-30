<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\CLI\DTO\SubmissionObserveCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use JsonException;

/** Reads the owning agent's current state through the test-only command channel. */
final class SubmissionTestObserveCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = [self::OPTION_ACCEPT_KEY];

    private const string OPTION_ACCEPT_KEY = 'accept-key';

    /** @return string Test-only command name */
    public function getName(): string
    {
        return ChatCommandConstants::SUBMISSION_TEST_OBSERVE;
    }

    /** @return string Help listing description */
    public function getDescription(): string
    {
        return 'Observe current Chat submission (test-only)';
    }

    /** @return string Required flags and production refusal */
    public function getHelp(): string
    {
        return 'Usage: php cli.php test:chat:submission-observe --accept-key=<acceptKey>'
            . "\nRefuses on a production-like environment.\n";
    }

    /**
     * @param array<string, mixed> $options Required observation coordinates
     * @param list<string> $args No positional arguments
     * @return int Exit code
     * @throws CommandException When the command name is not test-only
     * @throws InvalidFormatException When the coordinates cannot form a command payload
     * @throws JsonException When the successful reply cannot be encoded
     */
    protected function run(array $options, array $args): int
    {
        $acceptKey = $options[self::OPTION_ACCEPT_KEY] ?? null;
        if ($args !== [] || !is_string($acceptKey) || $acceptKey === '') {
            echo $this->getHelp();

            return ExitCode::INVALID_ARGUMENT;
        }
        try {
            $result = $this->sendCommand($this->getName(), new SubmissionObserveCommandData($acceptKey)->toArray());
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
        echo json_encode($result->reply->payload, JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}
