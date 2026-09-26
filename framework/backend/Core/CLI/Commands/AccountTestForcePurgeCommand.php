<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\AccountDeletion\AccountDeletionCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * Carries out one standing account-deletion request through the live daemon (test-only).
 *
 * The session holder first moves the request's erasure moment to now and then runs the same
 * erasure path as its scheduled sweep. This command only validates the user id, sends the
 * request, and renders the project's deletion tally. It is database-free because every row
 * is written by the answering agent.
 */
final class AccountTestForcePurgeCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * @return string Command name (test:account:force-purge)
     */
    public function getName(): string
    {
        return CliCommands::ACCOUNT_TEST_FORCE_PURGE;
    }

    /**
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Erase an account whose deletion is scheduled, now (test-only)';
    }

    /**
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:account:force-purge <userId>

Description:
  Move a standing account-deletion request's erasure moment to now and carry it
  out through the running session holder. Refuses on a production-like environment.

Arguments:
  <userId>  User whose standing deletion request is carried out

Usage:
  php cli.php test:account:force-purge 42
HELP;
    }

    /**
     * Sends the force-purge request and prints the project's erased-row tally.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] user id
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $userIdArgument = $args[0] ?? null;
        if (!is_string($userIdArgument) || !ctype_digit($userIdArgument) || (int)$userIdArgument <= 0) {
            echo "Usage: test:account:force-purge <userId>\n";

            return ExitCode::INVALID_ARGUMENT;
        }
        $userId = (int)$userIdArgument;

        try {
            $result = $this->sendCommand(
                $this->getName(),
                [AccountDeletionCommandConstants::FIELD_USER_ID => $userId],
            );
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

        $rowsErased = $result->reply->payload[AccountDeletionCommandConstants::FIELD_ROWS_ERASED] ?? null;
        if (!is_array($rowsErased)) {
            echo "Command failed: the reply carries no erased rows\n";

            return ExitCode::ERROR;
        }

        $parts = [];
        foreach ($rowsErased as $family => $count) {
            $parts[] = (string)$family . '=' . (int)$count;
        }
        $tally = $parts === [] ? 'no project rows' : implode(', ', $parts);
        echo "Erased the account of user {$userId} ({$tally})\n";

        return ExitCode::SUCCESS;
    }
}
