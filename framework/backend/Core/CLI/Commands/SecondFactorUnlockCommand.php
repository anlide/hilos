<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\SecondFactor\SecondFactorUnlockCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;

/**
 * SecondFactorUnlockCommand - lift the lock wrong app codes put on a person's second factor (HIL-1285).
 *
 * The operator's way out for a person whose app codes a guesser locked: whoever knows the
 * password can miss codes on purpose until the owner is locked out of their own app. Sent over
 * the daemon command channel; the users library ({@see AbstractUsersLibraryAgent}), which
 * checks the codes and counts the misses on the person's row, clears the lock, its step, the
 * count and its window, and answers whether a lock was in force and until when. The person is
 * not notified.
 *
 * Database-free ({@see DatabaseFreeCommand}): the row is written by the agent, so this process
 * needs no connection of its own.
 */
final class SecondFactorUnlockCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (second-factor:unlock)
     */
    public function getName(): string
    {
        return CliCommands::SECOND_FACTOR_UNLOCK;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return "Lift a person's second-factor app-code lock";
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()} <userId>

Description:
  Lift the lock wrong authenticator-app codes put on a person's second factor. The
  running daemon clears the lock, its step on the ladder, the miss count and its
  window, so the person's app codes are accepted again at once. Backup codes are
  never locked. A person with no lock is not an error: the miss count is cleared.
  The person is not notified.

Arguments:
  <userId>   Person id (positive integer)

Exit codes:
  0  the lock is lifted, or there was none
  1  the daemon did not answer, or refused the command (no such person)
  2  the user id argument is missing or not positive
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} 5
HELP;
    }

    /**
     * Declares where the work happens: the daemon lifts the lock, this process asks it to.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Lifts the person's app-code lock through the daemon command channel.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the person id
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the very next line
        $userId = (int)($args[0] ?? 0);
        if ($userId <= 0) {
            echo "A positive user id is required (e.g. {$this->getName()} 5)\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        echo "Unlocking second-factor app codes of user #{$userId}...\n";

        try {
            $result = $this->sendCommand($this->getName(), [SecondFactorUnlockCommandConstants::FIELD_USER_ID => $userId]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }

        $reply = $result->reply;
        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $lockedUntil = $reply->payload[SecondFactorUnlockCommandConstants::FIELD_LOCKED_UNTIL] ?? null;
        if ((bool)($reply->payload[SecondFactorUnlockCommandConstants::FIELD_WAS_LOCKED] ?? false) && is_string($lockedUntil)) {
            echo "Reply (ok): user #{$userId} was locked until {$lockedUntil}; app codes are accepted again\n";
        } else {
            echo "Reply (ok): user #{$userId} had no lock; the miss count is cleared\n";
        }

        return ExitCode::SUCCESS;
    }
}
