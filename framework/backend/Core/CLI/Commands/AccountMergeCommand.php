<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Users\AccountMergeCommandConstants;

/**
 * Merges one populated account into another through the daemon command channel (HIL-378).
 *
 * Sends the command over the daemon command channel; the daemon parks the connection, routes
 * it to the agent that answers the name (which re-points the loser's sign-in identities and
 * the rows the project keeps for it, tombstones the loser, and forces its live sessions to
 * log out - all in one transaction), and writes the reply back. Prints what moved.
 *
 * `--password` is where the operator says whose password the merged account keeps
 * (HIL-692). It is asked for only when it is a real question - two accounts each holding
 * one - and the absent key says so, so the ordinary merge keeps the shape it had. The
 * reply is read back as an outcome rather than an echo: naming an account that has no
 * password is allowed, and what gets printed is what the person ended up with.
 *
 * Real operator command, not test-only, and database-free ({@see DatabaseFreeCommand}) for
 * the reason its neighbours are: every row it causes is written where the accounts live, so
 * the CLI process needs no connection of its own.
 */
final class AccountMergeCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

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
        return 'Merge one populated account into another through the daemon command channel';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: account:merge

Description:
  Merge one populated account (the loser) into another (the survivor). Sends the
  command over the daemon command channel; the daemon routes it to the agent that
  answers it, which re-points the loser's sign-in identities and the rows this
  project keeps for a person, tombstones the loser (merged_into + login closed),
  and forces the loser's live sessions to log out. The survivor keeps its own
  profile. The whole transfer runs in one transaction; on any failure nothing is
  written.

  An account holds at most one password. While at most one of the two accounts
  has one, it survives and nothing needs saying; when BOTH have one the merge
  refuses until --password names which stays. A password that does not stay is
  not deleted with its address - the row becomes a sign-in-link address, so the
  person keeps every address they had.

Usage:
  php cli.php account:merge <survivorId> <loserId> [--password=survivor|loser|none]

Options:
  --password=survivor  keep the survivor's password
  --password=loser     keep the loser's password
  --password=none      keep neither; the person sets one anew in their profile

Examples:
  php cli.php account:merge 3 7
  php cli.php account:merge 3 7 --password=loser
HELP;
    }

    /**
     * Sends the account-merge command through the channel and prints the outcome.
     *
     * An omitted --password is a legitimate request and not a missing argument: it says
     * the operator has nothing to decide, and the merge refuses on its own if it turns
     * out otherwise. A value outside the three is rejected here, before anything is sent.
     *
     * @param array<string, mixed> $options Parsed options (password fate)
     * @param list<string> $args Positional args: [0] survivor id, [1] loser id
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        // external-boundary: positional arguments the operator types; the check below rejects them
        $survivorId = (int)($args[0] ?? 0);
        $loserId = (int)($args[1] ?? 0);
        if ($survivorId <= 0 || $loserId <= 0) {
            echo "Two positive user ids are required (e.g. {$this->getName()} <survivorId> <loserId>)\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $payload = [
            AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivorId,
            AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loserId,
        ];
        if (isset($options[AccountMergeCommandConstants::OPTION_PASSWORD])) {
            // external-boundary: an option the operator types by hand; the error below rejects a bad one
            $passwordRaw = (string)$options[AccountMergeCommandConstants::OPTION_PASSWORD];
            $passwordFate = PasswordFate::tryFrom($passwordRaw);
            if ($passwordFate === null) {
                echo "Unknown --password value: {$passwordRaw} (expected survivor, loser or none)\n";

                return ExitCode::INVALID_ARGUMENT;
            }

            $payload[AccountMergeCommandConstants::FIELD_PASSWORD_FATE] = $passwordFate->value;
        }

        echo "Merging user #{$loserId} into #{$survivorId}...\n";

        try {
            $result = $this->sendCommand($this->getName(), $payload);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            $detail = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";

            return ExitCode::ERROR;
        }

        echo "Reply (ok): merged user #{$loserId} into #{$survivorId} ({$this->describeTransfer($reply)})\n";
        echo 'Password: ' . $this->passwordOutcome($reply) . "\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Words what the merge carried over, naming each family of rows the way the project did.
     *
     * The identity count comes first because it is the framework's own and is always there;
     * what follows is whatever the project reported, and a project that moves nothing of its
     * own simply adds nothing to the line.
     *
     * @param CommandReplyDTO $reply Ok reply of the merge command
     * @return string Comma-separated tally, e.g. "2 identities, 1 messages moved"
     */
    private function describeTransfer(CommandReplyDTO $reply): string
    {
        // external-boundary: the daemon's reply payload is untyped on this side
        $identities = (int)($reply->payload[AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED] ?? 0);
        $rowsMoved = $reply->payload[AccountMergeCommandConstants::FIELD_ROWS_MOVED] ?? [];

        $parts = ["{$identities} identities"];
        if (is_array($rowsMoved)) {
            foreach ($rowsMoved as $family => $moved) {
                $parts[] = (int)$moved . ' ' . (string)$family;
            }
        }

        return implode(', ', $parts) . ' moved';
    }

    /**
     * Words what the merge did with the password, from what it actually did.
     *
     * Printed on every merge and not only on a decided one, because "nothing survived"
     * is the outcome an operator most needs to hear: whoever they merged has no way in
     * by password until they set one, and no other line of this reply would say so.
     *
     * @param CommandReplyDTO $reply Ok reply of the merge command
     * @return string One line naming whose password the account kept
     */
    private function passwordOutcome(CommandReplyDTO $reply): string
    {
        // external-boundary: a reply key an older daemon may not carry; the default reads as unknown
        $kept = PasswordFate::tryFrom((string)($reply->payload[AccountMergeCommandConstants::FIELD_PASSWORD_KEPT] ?? ''));

        return match ($kept) {
            PasswordFate::SURVIVOR => "the survivor's password stayed",
            PasswordFate::LOSER => "the loser's password stayed and now opens the account",
            PasswordFate::NONE => 'the account has no password; it can be set in the profile',
            default => 'not reported by the daemon',
        };
    }
}
