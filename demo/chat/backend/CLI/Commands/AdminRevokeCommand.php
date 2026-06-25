<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;

/**
 * Revokes a user's admin rights through the daemon command channel.
 */
final class AdminRevokeCommand extends AbstractSetAdminCommand
{
    public function getName(): string
    {
        return ChatCliCommands::ADMIN_REVOKE;
    }

    public function getDescription(): string
    {
        return "Revoke a user's admin rights through the daemon command channel";
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: admin:revoke

Description:
  Revoke a user's admin rights. Sends a setAdmin command over the daemon command
  channel; the daemon routes it to the chat agent, which clears the user's admin
  flag and fans the change out to everyone viewing the users list.

Usage:
  php cli.php admin:revoke <userId>

Examples:
  php cli.php admin:revoke 5
HELP;
    }

    protected function targetAdmin(): bool
    {
        return false;
    }
}
