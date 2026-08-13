<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;

/**
 * AdminGrantCommand - give a user the admin flag that opens the Hilos admin pages.
 *
 * The operator half of the admin surface: the pages under `/hilos` refuse everyone until a
 * user's row says admin, and nothing in the product grants the first one. Idempotent - a
 * user who is already an admin is answered ok without a write.
 */
class AdminGrantCommand extends AbstractSetAdminCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (admin:grant)
     */
    public function getName(): string
    {
        return CliCommands::ADMIN_GRANT;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Grant a user the Hilos admin flag';
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
  Give a user the admin flag the Hilos admin pages ask for. The running daemon writes
  the flag and re-sends the handshake response to that user's open browsers, so the
  admin entry appears without a reload. Granting an existing admin is not an error.

Arguments:
  <userId>   Target user id (positive integer)

Exit codes:
  0  the user is now an admin
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
     * @return bool Always true - this command grants
     */
    protected function targetAdmin(): bool
    {
        return true;
    }
}
