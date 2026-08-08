<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Demo\Chat\CLI\Commands\AccountMergeCommand;
use Demo\Chat\CLI\Commands\AdminGrantCommand;
use Demo\Chat\CLI\Commands\AdminRevokeCommand;
use Demo\Chat\CLI\Commands\BackupRestoreRunCommand;
use Demo\Chat\CLI\Commands\BackupRunCommand;
use Demo\Chat\CLI\Commands\CreateOrphanCommand;
use Demo\Chat\CLI\Commands\CreateOrphanSettingCommand;
use Demo\Chat\CLI\Commands\DeleteOrphanCommand;
use Demo\Chat\CLI\Commands\DeleteOrphanSettingCommand;
use Demo\Chat\CLI\Commands\EchoCommand;
use Demo\Chat\CLI\Commands\ImpersonateStartCommand;
use Demo\Chat\CLI\Commands\ImpersonateStopCommand;
use Demo\Chat\CLI\Commands\SessionExpireCommand;
use Hilos\Core\CLI\CliManager;

/**
 * Chat CLI manager — registers the chat's project commands on top of the framework set.
 *
 * The test-only state helpers (orphan-setting create/delete, the command-channel echo probe)
 * demonstrate the TestOnlyCommand mechanism; admin grant/revoke and impersonate start/stop are
 * real operator commands that act on a user or session over the daemon command channel;
 * account:merge folds one populated account into another over the same channel;
 * backup:run is the short-lived child the backup agent spawns to create one backup archive.
 */
final class ChatCliManager extends CliManager
{
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new CreateOrphanSettingCommand());
        $this->addCommand(new DeleteOrphanSettingCommand());
        $this->addCommand(new CreateOrphanCommand());
        $this->addCommand(new DeleteOrphanCommand());
        $this->addCommand(new EchoCommand());
        $this->addCommand(new AdminGrantCommand());
        $this->addCommand(new AdminRevokeCommand());
        $this->addCommand(new ImpersonateStartCommand());
        $this->addCommand(new ImpersonateStopCommand());
        $this->addCommand(new AccountMergeCommand());
        $this->addCommand(new SessionExpireCommand());
        $this->addCommand(new BackupRunCommand());
        $this->addCommand(new BackupRestoreRunCommand());
    }
}
