<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\AccountMergeProgress;
use Hilos\Users\AccountMergeSummary;
use Hilos\Users\SecondFactorFate;
use JsonException;

/**
 * Prepared account:merge client, unregistered until the live merge switch.
 *
 * SCAFFOLD: unregistered prepared replacement until HIL-1999.
 *
 * The merge is distributed across the owners that record its parts. This process
 * only chooses start or observe, waits for one reply, and prints the saved merge.
 * A lost reply does not cancel the operation, and neither does Ctrl+C.
 * Not final: a test stands in for the channel.
 */
class AccountMergeOwnerCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /** @var list<string> Option names this command reads, without the leading dashes */
    public const array OPTIONS = [
        AccountMergeCommandConstants::OPTION_PASSWORD,
        AccountMergeCommandConstants::OPTION_SECOND_FACTOR,
        AccountMergeCommandConstants::OPTION_OBSERVE,
        AccountMergeCommandConstants::OPTION_OPERATION,
    ];

    /** @var list<string> Keys an ok merge reply may carry */
    private const array OBSERVED_REPLY_KEYS = [
        AccountMergeCommandConstants::FIELD_MERGE,
        AccountMergeCommandConstants::FIELD_SUMMARY,
        CommandConstants::FIELD_MESSAGE,
    ];

    public function getName(): string
    {
        return CliCommands::ACCOUNT_MERGE;
    }

    /**
     * Declares the rule: the daemon merges the accounts and this process only asks it to.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    public function getDescription(): string
    {
        return 'Merge one account into another, or observe a merge, through the daemon command channel';
    }

    /**
     * Describes the distributed merge, the bounded wait, and how to observe it again.
     *
     * @return string Full help text with usage examples
     */
    public function getHelp(): string
    {
        $seconds = (int)CommandChannelWindows::CALLER_WAIT_SECONDS;
        $timeout = ExitCode::TIMEOUT;

        return <<<HELP
Command: account:merge

Description:
  Ask the daemon to merge one account into another, or to report a merge that
  is already recorded. The merge is distributed: each owner records its own
  part, and there is no single transaction around the whole merge. This process
  waits up to {$seconds}s for a reply. That wait is not the merge.

  A reply shows preparing, wait, refusal, cancelling, or a durable end. An open
  snapshot, including a refusal that has not finished, exits {$timeout}; observe
  it again. Success is printed only for a durable end.

  The channel closing, a timeout, or Ctrl+C stops this process only. Ctrl+C
  does not cancel the merge. Observe a known operation with --operation, or the
  same pair with --observe. The command chooses start or observe from the form
  you type.

Usage:
  php cli.php account:merge <survivorId> <loserId> [--password=survivor|loser|none] [--second-factor=survivor|both]
  php cli.php account:merge --operation=<id>
  php cli.php account:merge <survivorId> <loserId> --observe

Options:
  --password=survivor|loser|none  whose password to keep, only when starting
  --second-factor=survivor|both   whose confirmed authenticators to keep, only when starting
  --operation=<id>                watch one saved operation, not with a pair, --observe, or a fate
  --observe                       watch the latest merge of a pair, not with a fate

  An omitted fate is left unnamed. It is not sent as none.

Examples:
  php cli.php account:merge 3 7 --password=loser
  php cli.php account:merge --operation=15
  php cli.php account:merge 3 7 --observe
HELP;
    }

    /**
     * Sends start or observe and prints the saved merge.
     *
     * An invalid form returns before the channel is opened. A lost reply names the
     * outcome unknown and tells the operator how to observe; it does not repeat the start.
     *
     * @param array<string, mixed> $options Declared options only
     * @param list<string> $args Positional survivor and loser ids, when the form has them
     * @return int Exit code: 0 completed, 6 still open, 1 refused or unreadable or unanswered, 2 invalid, 3 config
     */
    public function execute(array $options, array $args): int
    {
        $payload = $this->payload($options, $args);
        if ($payload === null) {
            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), $payload);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            $code = $this->printChannelFailure($result, $this->getName());
            $this->writeToStandardError(
                'The merge outcome is unknown; the channel failure did not cancel it.',
            );
            $this->writeToStandardError($this->observeCommand($payload));

            return $code;
        }

        return $this->printObserved($result->reply, $payload);
    }

    /**
     * Builds the start or observe payload, or rejects a form that is not one of the three.
     *
     * An omitted fate is left out of the payload. It is not stored as none.
     *
     * @param array<string, mixed> $options Declared options only
     * @param list<string> $args Positional arguments
     * @return ?array<string, int|string> Payload to send, or null when the form is invalid
     */
    private function payload(array $options, array $args): ?array
    {
        foreach ([...$options] as $name => $_) {
            if (!in_array($name, self::OPTIONS, true)) {
                return $this->invalid();
            }
        }
        $observing = array_key_exists(AccountMergeCommandConstants::OPTION_OBSERVE, $options);
        $namingOperation = array_key_exists(AccountMergeCommandConstants::OPTION_OPERATION, $options);
        $namingPassword = array_key_exists(AccountMergeCommandConstants::OPTION_PASSWORD, $options);
        $namingSecondFactor = array_key_exists(AccountMergeCommandConstants::OPTION_SECOND_FACTOR, $options);
        if ($namingOperation) {
            if ($args !== [] || $observing || $namingPassword || $namingSecondFactor) {
                return $this->invalid();
            }
            $operationId = $this->positiveId($options[AccountMergeCommandConstants::OPTION_OPERATION]);
            if ($operationId === null) {
                return $this->invalid();
            }

            return [
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                AccountMergeCommandConstants::FIELD_OPERATION_ID => $operationId,
            ];
        }
        if ($observing) {
            if ($options[AccountMergeCommandConstants::OPTION_OBSERVE] !== true
                || $namingPassword || $namingSecondFactor || count($args) !== 2) {
                return $this->invalid();
            }
            $survivorId = $this->positiveId($args[0]);
            $loserId = $this->positiveId($args[1]);
            if ($survivorId === null || $loserId === null) {
                return $this->invalid();
            }

            return [
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivorId,
                AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loserId,
            ];
        }
        if (count($args) !== 2) {
            return $this->invalid();
        }
        $survivorId = $this->positiveId($args[0]);
        $loserId = $this->positiveId($args[1]);
        if ($survivorId === null || $loserId === null) {
            return $this->invalid();
        }
        $payload = [
            AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START,
            AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivorId,
            AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loserId,
        ];
        if ($namingPassword) {
            $passwordRaw = $options[AccountMergeCommandConstants::OPTION_PASSWORD];
            $passwordFate = is_string($passwordRaw) ? PasswordFate::tryFrom($passwordRaw) : null;
            if ($passwordFate === null) {
                echo "Usage: unknown --password value (expected survivor, loser or none)\n";

                return null;
            }
            $payload[AccountMergeCommandConstants::FIELD_PASSWORD_FATE] = $passwordFate->value;
        }
        if ($namingSecondFactor) {
            $secondFactorRaw = $options[AccountMergeCommandConstants::OPTION_SECOND_FACTOR];
            $secondFactorFate = is_string($secondFactorRaw) ? SecondFactorFate::tryFrom($secondFactorRaw) : null;
            if ($secondFactorFate === null) {
                echo "Usage: unknown --second-factor value (expected survivor or both)\n";

                return null;
            }
            $payload[AccountMergeCommandConstants::FIELD_SECOND_FACTOR_FATE] = $secondFactorFate->value;
        }

        return $payload;
    }

    /**
     * Prints the three accepted forms and rejects the invocation.
     *
     * @return null Always null, so the caller returns the invalid-argument code
     */
    private function invalid(): null
    {
        echo "Usage: account:merge <survivorId> <loserId> [--password=survivor|loser|none]"
            . " [--second-factor=survivor|both] | account:merge --operation=<id>"
            . " | account:merge <survivorId> <loserId> --observe\n";

        return null;
    }

    /**
     * Reads one positive account id from a token the operator typed.
     *
     * @param mixed $token Positional argument or option value
     * @return ?int The id, or null when the token is not a positive decimal integer
     */
    private function positiveId(mixed $token): ?int
    {
        if (!is_string($token) || !ctype_digit($token)) {
            return null;
        }
        $id = (int)$token;

        return $id > 0 ? $id : null;
    }

    /**
     * Prints one saved merge, or refuses a reply that is not the merge that was asked for.
     *
     * An ok reply is readable only with merge and summary, plus message on a not-started
     * end that has no refusal. Any other key, a message on another branch, or a negative
     * tally is unreadable. Observing a pair names that record as the latest before the
     * rest of the snapshot; start and an operation watch do not.
     *
     * @param CommandReplyDTO $reply Daemon reply
     * @param array<string, int|string> $payload Request this command sent
     * @return int Exit code for the snapshot
     */
    private function printObserved(CommandReplyDTO $reply, array $payload): int
    {
        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }
        if (array_diff(array_keys($reply->payload), self::OBSERVED_REPLY_KEYS) !== []) {
            return $this->printUnreadable();
        }
        $merge = $reply->payload[AccountMergeCommandConstants::FIELD_MERGE] ?? null;
        if (!is_array($merge) || !array_key_exists(AccountMergeCommandConstants::FIELD_SUMMARY, $reply->payload)) {
            return $this->printUnreadable();
        }
        try {
            $progress = AccountMergeProgress::fromArray($merge);
            $summary = $this->strictSummary($progress, $reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        } catch (InvalidFormatException | InvalidJsonException | JsonException) {
            return $this->printUnreadable();
        }
        if ($this->forbiddenMessage($progress, $reply)) {
            return $this->printUnreadable();
        }
        if (!$this->sameObservation($progress, $payload)) {
            return $this->printDifferentObservation();
        }
        if ($summary !== null) {
            $this->printIdentity($progress, $payload);
            echo 'Reply (ok): merged user #' . $progress->loserUserId . ' into #' . $progress->survivorUserId
                . ' (' . $this->tally($summary) . ")\n";
            echo 'Password: ' . $this->passwordOutcome($summary) . "\n";
            echo 'Second factor: ' . $summary->secondFactorKept->describe() . "\n";

            return ExitCode::SUCCESS;
        }
        if ($progress->outcome === AccountOperationOutcome::NotStarted->value) {
            $reason = $this->terminalReason($reply, $progress);
            if ($reason === null) {
                return $this->printUnreadable();
            }
            $this->printIdentity($progress, $payload);

            return $progress->refusal === null
                ? $this->printRefusal($reply)
                : $this->printRefusal(CommandReplyDTO::error($reply->correlationId, $reason));
        }
        if ($progress->outcome !== null) {
            return $this->printUnreadable();
        }
        $this->printIdentity($progress, $payload);
        echo $this->openLine($progress);
        if ($progress->refusal !== null) {
            echo $progress->refusal[AccountMergeProgress::reason] . "\n";
        }
        echo 'Observe it with account:merge --operation=' . $progress->operationId . "\n";

        return ExitCode::TIMEOUT;
    }

    /**
     * Reads the summary only on a completed end, and only when it is strict.
     *
     * @param AccountMergeProgress $progress Saved merge
     * @param mixed $summary Summary field of the reply, null until a completed end
     * @return ?AccountMergeSummary Summary of a completed end, or null when this end has none
     * @throws InvalidFormatException When the summary is present on another branch, missing on a completed end, not strict, or negative
     */
    private function strictSummary(AccountMergeProgress $progress, mixed $summary): ?AccountMergeSummary
    {
        $completed = $progress->state === AccountMergeProgress::END
            && $progress->outcome === AccountOperationOutcome::Completed->value;
        if ($summary === null) {
            if ($completed) {
                throw new InvalidFormatException('A completed merge reply requires its summary.');
            }

            return null;
        }
        if (!$completed || !is_array($summary)) {
            throw new InvalidFormatException('A merge reply carries a summary only for a completed end.');
        }
        $parsed = AccountMergeSummary::fromArray($summary);
        if ($parsed->identitiesMoved < 0) {
            throw new InvalidFormatException('A merge reply cannot report a negative identity count.');
        }
        foreach ($parsed->rowsMoved as $moved) {
            if ($moved < 0) {
                throw new InvalidFormatException('A merge reply cannot report a negative row count.');
            }
        }

        return $parsed;
    }

    /**
     * @param AccountMergeProgress $progress Saved merge the reply names
     * @param array<string, int|string> $payload Request this command sent
     * @return bool Whether the reply is the operation or the pair that was asked for
     */
    private function sameObservation(AccountMergeProgress $progress, array $payload): bool
    {
        if (array_key_exists(AccountMergeCommandConstants::FIELD_OPERATION_ID, $payload)) {
            return $progress->operationId === $payload[AccountMergeCommandConstants::FIELD_OPERATION_ID];
        }

        return $progress->survivorUserId === $payload[AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID]
            && $progress->loserUserId === $payload[AccountMergeCommandConstants::FIELD_LOSER_USER_ID];
    }

    /**
     * Prints the latest-record lead for a pair observation, then the operation, the pair, and the choices.
     *
     * @param AccountMergeProgress $progress Saved merge
     * @param array<string, int|string> $payload Request this command sent
     */
    private function printIdentity(AccountMergeProgress $progress, array $payload): void
    {
        if ($this->isPairObservation($payload)) {
            echo 'The latest recorded merge for this pair is operation ' . $progress->operationId . ".\n";
        }
        echo $this->identityLine($progress);
    }

    /**
     * @param array<string, int|string> $payload Request this command sent
     * @return bool Whether the request observes a pair rather than starting or naming an operation
     */
    private function isPairObservation(array $payload): bool
    {
        return array_key_exists(AccountMergeCommandConstants::FIELD_MODE, $payload)
            && $payload[AccountMergeCommandConstants::FIELD_MODE] === AccountMergeCommandConstants::MODE_OBSERVE
            && !array_key_exists(AccountMergeCommandConstants::FIELD_OPERATION_ID, $payload)
            && array_key_exists(AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID, $payload)
            && array_key_exists(AccountMergeCommandConstants::FIELD_LOSER_USER_ID, $payload);
    }

    /**
     * @param AccountMergeProgress $progress Saved merge the reply names
     * @param CommandReplyDTO $reply Ok reply
     * @return bool Whether the reply carries a message this branch forbids
     */
    private function forbiddenMessage(AccountMergeProgress $progress, CommandReplyDTO $reply): bool
    {
        if (!array_key_exists(CommandConstants::FIELD_MESSAGE, $reply->payload)) {
            return false;
        }

        return $progress->outcome !== AccountOperationOutcome::NotStarted->value
            || $progress->refusal !== null;
    }

    /**
     * @param AccountMergeProgress $progress Saved merge
     * @return string Operation, pair, and the saved choice fates, with a line break
     */
    private function identityLine(AccountMergeProgress $progress): string
    {
        return 'Operation ' . $progress->operationId
            . ', pair ' . $progress->survivorUserId . ' ' . $progress->loserUserId
            . ', password ' . $this->fateWord($progress->choice->passwordFate?->value)
            . ', second factor ' . $this->fateWord($progress->choice->secondFactorFate?->value)
            . "\n";
    }

    /**
     * @param ?string $fate Saved choice, or null when the merge left it unnamed
     * @return string The fate's own word, or the words for an unnamed choice
     */
    private function fateWord(?string $fate): string
    {
        return $fate ?? 'not specified';
    }

    /**
     * @param AccountMergeSummary $summary Strict completed tally
     * @return string Identities and project rows, in the order the summary carries them
     */
    private function tally(AccountMergeSummary $summary): string
    {
        $parts = [$summary->identitiesMoved . ' identities'];
        foreach ($summary->rowsMoved as $family => $moved) {
            $parts[] = $moved . ' ' . $family;
        }

        return implode(', ', $parts) . ' moved';
    }

    /**
     * @param AccountMergeSummary $summary Strict completed tally
     * @return string Whose password the merged account ended up with
     */
    private function passwordOutcome(AccountMergeSummary $summary): string
    {
        return match ($summary->passwordKept) {
            PasswordFate::SURVIVOR => "the survivor's password stayed",
            PasswordFate::LOSER => "the loser's password stayed and now opens the account",
            PasswordFate::NONE => 'the account has no password; it can be set in the profile',
        };
    }

    /**
     * @param AccountMergeProgress $progress Open merge
     * @return string Stage, parts done, and outstanding waits, with a line break
     */
    private function openLine(AccountMergeProgress $progress): string
    {
        $done = 0;
        foreach ($progress->parts as $part) {
            $done += $part[AccountMergeProgress::done] ? 1 : 0;
        }
        $stage = $progress->stage === $progress->state
            ? $progress->state
            : $progress->state . ' ' . $progress->stage;
        $line = $stage . ': ' . $done . ' of ' . count($progress->parts) . ' parts done';
        if ($progress->waits !== []) {
            $waited = [];
            foreach ($progress->waits as $wait) {
                $waited[] = $wait[AccountMergeProgress::participant]
                    . ' (user ' . $wait[AccountMergeProgress::userId] . ')';
            }
            $line .= '; waiting for ' . implode(', ', $waited);
        }

        return $line . "\n";
    }

    /**
     * @param CommandReplyDTO $reply Ok reply of a merge that ended without completing
     * @param AccountMergeProgress $progress That merge
     * @return ?string Daemon sentence, or null when the reply does not carry one
     */
    private function terminalReason(CommandReplyDTO $reply, AccountMergeProgress $progress): ?string
    {
        if ($progress->refusal !== null) {
            return $progress->refusal[AccountMergeProgress::reason];
        }
        $message = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null;
        if (!is_string($message) || $message === '') {
            return null;
        }

        return $message;
    }

    /**
     * @return int {@see ExitCode::ERROR}
     */
    private function printUnreadable(): int
    {
        return $this->printToStandardError('The account merge reply cannot be read.');
    }

    /**
     * @return int {@see ExitCode::ERROR}
     */
    private function printDifferentObservation(): int
    {
        return $this->printToStandardError('The account merge reply names a different operation.');
    }

    /**
     * @param array<string, int|string> $payload Request whose reply did not arrive
     * @return string Command that observes the same merge
     */
    private function observeCommand(array $payload): string
    {
        if (array_key_exists(AccountMergeCommandConstants::FIELD_OPERATION_ID, $payload)) {
            return 'account:merge --operation=' . $payload[AccountMergeCommandConstants::FIELD_OPERATION_ID];
        }

        return 'account:merge ' . $payload[AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID]
            . ' ' . $payload[AccountMergeCommandConstants::FIELD_LOSER_USER_ID] . ' --observe';
    }
}
