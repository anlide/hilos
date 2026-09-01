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
        echo "Reply (ok): user #{$userId} is now {$state}\n";

        return ExitCode::SUCCESS;
    }

    /**
     * The admin flag this command sets on the target user.
     *
     * @return bool True to grant admin, false to revoke it
     */
    abstract protected function targetAdmin(): bool;
}
