<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\AccountDeletion\AccountDeletionCommandConstants;
use Hilos\Auth\AccountDeletion\AccountDeletionCommandOutcome;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Users\AccountErasureProgress;

/**
 * Carries out one standing account-deletion request through the live daemon (test-only).
 *
 * The session holder brings the request's erasure to now and answers with the outcome it
 * reached (HIL-1953): only a finished erasure prints the project's deletion tally, an
 * unfinished one prints the stage it reached and exits {@see ExitCode::TIMEOUT}, and a lost
 * reply says the outcome is unknown instead of guessing it. Running the command again watches
 * the same erasure. This command only validates the user id, sends the request, and renders
 * the reply. It is database-free because every row is written by the answering agent.
 */
class AccountTestForcePurgeCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
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
        $seconds = (int)CommandChannelWindows::AGENT_WAIT_SECONDS;
        $timeout = ExitCode::TIMEOUT;

        return <<<HELP
Command: test:account:force-purge <userId>

Description:
  Move a standing account-deletion request's erasure moment to now and carry it
  out through the running session holder. Refuses on a production-like environment.

  Waits up to {$seconds}s for the erasure to end. An unfinished erasure prints the stage
  it reached and exits {$timeout}; run the command again to follow it.

Arguments:
  <userId>  User whose standing deletion request is carried out

Usage:
  php cli.php test:account:force-purge 42
HELP;
    }

    /**
     * Sends the force-purge request and prints the outcome the erasure reached.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] user id
     * @return int Exit code: 0 when the erasure finished, 6 when it has not, 1 when refused or unanswered
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
            $code = $this->printChannelFailure($result, $this->getName());
            $this->writeToStandardError($this->unknownOutcomeText($userId));

            return $code;
        }

        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }

        $payload = $result->reply->payload;
        $outcome = $payload[AccountDeletionCommandConstants::FIELD_OUTCOME] ?? null;

        return match (is_string($outcome) ? AccountDeletionCommandOutcome::tryFrom($outcome) : null) {
            AccountDeletionCommandOutcome::Completed => $this->printErased(
                $userId,
                $payload[AccountDeletionCommandConstants::FIELD_ROWS_ERASED] ?? null,
            ),
            AccountDeletionCommandOutcome::Starting => $this->printUnfinished(
                "The erasure of user {$userId} has not started yet: its account's agent has not begun it",
            ),
            AccountDeletionCommandOutcome::Erasing => $this->printErasing(
                $userId,
                $payload[AccountDeletionCommandConstants::FIELD_ERASURE] ?? null,
            ),
            null => $this->printNoOutcome(),
        };
    }

    /**
     * Words why the erasure's outcome stays unknown when no reply arrived.
     *
     * Returned rather than printed for the reason {@see channelFailureText()} is: the sentence
     * goes to stderr, which no test here can read, and a returned text is one a test can pin.
     *
     * @param int $userId User whose erasure the command asked about
     * @return string Sentence naming the account, without its line break
     */
    protected function unknownOutcomeText(int $userId): string
    {
        return "Whether the erasure of user {$userId} began is unknown; run the command again to see where it stands";
    }

    /**
     * Prints the tally of a finished erasure.
     *
     * @param int $userId User whose erasure finished
     * @param mixed $rowsErased Rows erased per family, as the reply carries them
     * @return int Exit code: 0 for a readable tally, 1 when the reply carries none
     */
    private function printErased(int $userId, mixed $rowsErased): int
    {
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

    /**
     * Prints the stage of a begun erasure: its parts done, the owners it waits for, the finals refused.
     *
     * @param int $userId User whose erasure is under way
     * @param mixed $erasure Erasure progress as {@see AccountErasureProgress::toArray()} sends it
     * @return int Exit code: 6 for a readable stage, 1 when the progress cannot be read
     */
    private function printErasing(int $userId, mixed $erasure): int
    {
        $stage = is_array($erasure) ? $this->erasureStage($erasure) : null;
        if ($stage === null) {
            return $this->printNoOutcome();
        }

        return $this->printUnfinished("The erasure of user {$userId} has started and is not finished: {$stage}");
    }

    /**
     * Words the progress block of a begun erasure.
     *
     * @param array<mixed> $erasure Erasure progress as {@see AccountErasureProgress::toArray()} sends it
     * @return ?string Parts done of all, then waits and refusals when there are any; null when a field has another shape
     */
    private function erasureStage(array $erasure): ?string
    {
        $parts = $erasure[AccountErasureProgress::parts] ?? null;
        $waits = $erasure[AccountErasureProgress::waits] ?? null;
        $refusals = $erasure[AccountErasureProgress::refusals] ?? null;
        if (!is_array($parts) || !is_array($waits) || !is_array($refusals)) {
            return null;
        }

        $done = 0;
        foreach ($parts as $part) {
            if (!is_array($part) || !is_bool($part[AccountErasureProgress::done] ?? null)) {
                return null;
            }
            $done += $part[AccountErasureProgress::done] ? 1 : 0;
        }
        $stage = "{$done} of " . count($parts) . ' parts done';

        $waited = [];
        foreach ($waits as $wait) {
            $participant = is_array($wait) ? $wait[AccountErasureProgress::participant] ?? null : null;
            $waitUserId = is_array($wait) ? $wait[AccountErasureProgress::userId] ?? null : null;
            if (!is_string($participant) || !is_int($waitUserId)) {
                return null;
            }
            $waited[] = "{$participant} (user {$waitUserId})";
        }
        if ($waited !== []) {
            $stage .= '; waiting for an owner of ' . implode(', ', $waited);
        }

        $refused = [];
        foreach ($refusals as $refusal) {
            $refusalUserId = is_array($refusal) ? $refusal[AccountErasureProgress::userId] ?? null : null;
            $table = is_array($refusal) ? $refusal[AccountErasureProgress::table] ?? null : null;
            if (!is_int($refusalUserId) || ($table !== null && !is_string($table))) {
                return null;
            }
            $refused[] = $table === null ? "user {$refusalUserId}" : "user {$refusalUserId} (table {$table})";
        }
        if ($refused !== []) {
            $stage .= '; the database refuses to finish ' . implode(', ', $refused);
        }

        return $stage;
    }

    /**
     * Prints the stage an unfinished erasure reached, with the hint that a rerun follows it.
     *
     * @param string $stage Stage sentence naming the account, without the hint
     * @return int {@see ExitCode::TIMEOUT}: the erasure did not end within the wait
     */
    private function printUnfinished(string $stage): int
    {
        echo "{$stage}. Run the command again to follow it\n";

        return ExitCode::TIMEOUT;
    }

    /**
     * Prints that an ok reply named no outcome this command can read.
     *
     * @return int {@see ExitCode::ERROR}
     */
    private function printNoOutcome(): int
    {
        echo "Command failed: the reply carries no erasure outcome\n";

        return ExitCode::ERROR;
    }
}
