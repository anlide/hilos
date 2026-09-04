<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Environment\Exception\EnvException;
use Hilos\Users\AdminCommandConstants;

/**
 * Shared base for the admin grant/revoke CLI commands.
 *
 * Sends the command over the daemon command channel; the daemon parks the connection,
 * routes it to {@see AbstractHilosIndexAgent} (which writes the flag through the project
 * seam and tells the user's live connections), and writes the reply back. Subclasses
 * declare only the flag they set and their command identity.
 *
 * Real operator command, not test-only: an installation whose users are all non-admin has
 * no way into the Hilos admin pages, and this is how the first admin is made. It is also
 * database-free ({@see DatabaseFreeCommand}) - every row it causes is written by the agent,
 * so the CLI process needs no connection of its own.
 */
abstract class AbstractSetAdminCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Declares the rule for both subclasses: the daemon flips the flag, this process asks it to.
     *
     * @return CommandExecution Where this family's work happens
     */
    final public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Sets the target user's admin flag through the daemon command channel.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the target user id
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

        $verb = $this->targetAdmin() ? 'Granting admin to' : 'Revoking admin from';
        echo "{$verb} user #{$userId}...\n";

        try {
            $result = $this->sendCommand($this->getName(), [
                AdminCommandConstants::FIELD_USER_ID => $userId,
                AdminCommandConstants::FIELD_ADMIN => $this->targetAdmin(),
            ]);
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

        $state = ((bool)($reply->payload[AdminCommandConstants::FIELD_ADMIN] ?? false)) ? 'admin' : 'not admin';
        $sessions = (int)($reply->payload[AdminCommandConstants::FIELD_ANNOUNCED_SESSIONS] ?? 0);
        // A reply WITHOUT the key is read as nothing to complain about, not as a failure: this
        // process is new on every invocation while the daemon it asks is long-running, so a
        // deploy that has not restarted the daemon yet gets answers from the older one - which
        // announces perfectly well and merely does not report it. Defaulting the other way
        // would print an alarm, with no reason after the colon, over a grant that went fine.
        if (!(bool)($reply->payload[AdminCommandConstants::FIELD_ANNOUNCED] ?? true)) {
            // external-boundary: the daemon's reply, whose announced=false always names its reason
            $announceError = (string)($reply->payload[AdminCommandConstants::FIELD_ANNOUNCE_ERROR] ?? '');
            // Said before the outcome, for the reason AdminCreateCommand says its expiry line
            // there: the result is what stays last on the screen and reads as the verdict.
            $this->writeToStandardError("The change was not announced to every tab: {$announceError}.");
            $this->writeToStandardError(
                'The tabs that were not told keep the previous rights until they reconnect.',
            );
        }
        $told = $sessions > 0 ? "{$sessions} open session(s) told" : 'no open session to tell';
        echo "Reply (ok): user #{$userId} is now {$state}, {$told}\n";

        // Success even when the announcement failed: the flag IS written, and the exit code
        // answers whether anything is left to do rather than whether everything worked - a
        // repeat of this command would write the same flag and fix nothing.
        return ExitCode::SUCCESS;
    }

    /**
     * The admin flag this command sets on the target user.
     *
     * @return bool True to grant admin, false to revoke it
     */
    abstract protected function targetAdmin(): bool;
}
