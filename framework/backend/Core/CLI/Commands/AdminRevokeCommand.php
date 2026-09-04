<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;

/**
 * AdminRevokeCommand - take the Hilos admin flag away from a user.
 *
 * The pair of {@see AdminGrantCommand}, and the reason a grant is safe to hand out: a
 * mistaken admin can be undone from the same place. Idempotent - a user who is not an
 * admin is answered ok without a write.
 */
class AdminRevokeCommand extends AbstractSetAdminCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (admin:revoke)
     */
    public function getName(): string
    {
        return CliCommands::ADMIN_REVOKE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Take the Hilos admin flag away from a user';
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
  Take the Hilos admin flag away from a user. The running daemon writes the flag and
  re-sends the handshake response to that user's open browsers, so the admin entry
  disappears without a reload. Revoking from a non-admin is not an error.

Arguments:
  <userId>   Target user id (positive integer)

Exit codes:
  0  the user is no longer an admin; this is also the answer when the change could not
     be announced to every open tab, which stderr reports on its own lines. The
     flag is written either way, so repeating the command would change nothing
  1  the daemon did not answer, or refused the command
  2  the user id argument is missing or not positive
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} 5
HELP;
    }

    /**
     * The admin flag this command sets on the target user.
     *
     * @return bool Always false - this command revokes
     */
    protected function targetAdmin(): bool
    {
        return false;
    }
}
