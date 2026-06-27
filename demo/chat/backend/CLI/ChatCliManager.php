<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Demo\Chat\CLI\Commands\AdminGrantCommand;
use Demo\Chat\CLI\Commands\AdminRevokeCommand;
use Demo\Chat\CLI\Commands\CreateOrphanSettingCommand;
use Demo\Chat\CLI\Commands\DeleteOrphanSettingCommand;
use Demo\Chat\CLI\Commands\EchoCommand;
use Hilos\Core\CLI\CliManager;

/**
 * Chat CLI manager — registers the chat's project commands on top of the framework set.
 *
 * The test-only state helpers (orphan-setting create/delete, the command-channel echo probe)
 * demonstrate the TestOnlyCommand mechanism; admin grant/revoke are real operator commands
 * that flip a user's admin flag over the daemon command channel.
 */
final class ChatCliManager extends CliManager
{
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new CreateOrphanSettingCommand());
        $this->addCommand(new DeleteOrphanSettingCommand());
        $this->addCommand(new EchoCommand());
        $this->addCommand(new AdminGrantCommand());
        $this->addCommand(new AdminRevokeCommand());
    }
}
