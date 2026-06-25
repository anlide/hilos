<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;

/**
 * Grants a user admin rights through the daemon command channel.
 */
final class AdminGrantCommand extends AbstractSetAdminCommand
{
    public function getName(): string
    {
        return ChatCliCommands::ADMIN_GRANT;
    }

    public function getDescription(): string
    {
        return 'Grant a user admin rights through the daemon command channel';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: admin:grant

Description:
  Grant a user admin rights. Sends a setAdmin command over the daemon command
  channel; the daemon routes it to the chat agent, which flips the user's admin
  flag and fans the change out to everyone viewing the users list.

Usage:
  php cli.php admin:grant <userId>

Examples:
  php cli.php admin:grant 5
HELP;
    }

    protected function targetAdmin(): bool
    {
        return true;
    }
}
