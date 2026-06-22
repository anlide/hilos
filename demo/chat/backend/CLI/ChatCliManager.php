<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Demo\Chat\CLI\Commands\CreateOrphanSettingCommand;
use Demo\Chat\CLI\Commands\DeleteOrphanSettingCommand;
use Hilos\Core\CLI\CliManager;

/**
 * Chat CLI manager — registers the chat's project commands on top of the framework set.
 *
 * Currently only the test-only state helpers (orphan-setting create/delete), the worked example
 * of the TestOnlyCommand mechanism.
 */
final class ChatCliManager extends CliManager
{
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new CreateOrphanSettingCommand());
        $this->addCommand(new DeleteOrphanSettingCommand());
    }
}
