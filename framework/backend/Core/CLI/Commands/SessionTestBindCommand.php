<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\Session\SessionToken;
use Hilos\Auth\TestUserCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use JsonException;

/** Binds an anonymous fixture browser through the live sessions library. */
class SessionTestBindCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    public const array OPTIONS = [];

    /** @return string Test-only command name */
    public function getName(): string
    {
        return CliCommands::SESSION_TEST_BIND;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Bind an anonymous fixture session to a user (test-only)';
    }

    /** @return string Terminal syntax */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:session:bind <sessionToken> <userId>

Description:
  Bind an idle anonymous session to an existing user through the live daemon.
  Refuses on a production-like environment.
HELP;
    }

    /**
     * @param array<string, mixed> $options Unused options
     * @param list<string> $args Session token and decimal person id
     * @return int Exit code
     * @throws CommandException When the command name is not test-only
     * @throws JsonException When the success payload cannot be encoded
     */
    protected function run(array $options, array $args): int
    {
        $token = $args[0] ?? null;
        $userIdArgument = $args[1] ?? null;
        if (count($args) !== 2 || !is_string($token) || !SessionToken::isValid($token)
            || !is_string($userIdArgument) || !ctype_digit($userIdArgument)
            || (int)$userIdArgument <= 0 || (string)(int)$userIdArgument !== $userIdArgument) {
            echo "Usage: test:session:bind <sessionToken> <userId>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $userId = (int)$userIdArgument;
        try {
            $result = $this->sendCommand($this->getName(), [
                TestUserCommandConstants::FIELD_SESSION_TOKEN => $token,
                TestUserCommandConstants::FIELD_USER_ID => $userId,
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
        $sessionId = $result->reply->payload[TestUserCommandConstants::FIELD_SESSION_ID] ?? null;
        $replyUserId = $result->reply->payload[TestUserCommandConstants::FIELD_USER_ID] ?? null;
        if (!is_int($sessionId) || $sessionId <= 0 || !is_int($replyUserId) || $replyUserId !== $userId) {
            echo "Command failed: the reply carries no bound session\n";

            return ExitCode::ERROR;
        }

        echo json_encode([
            TestUserCommandConstants::FIELD_SESSION_ID => $sessionId,
            TestUserCommandConstants::FIELD_USER_ID => $replyUserId,
        ], JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}
