<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Backup;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Backup\AbstractHilosBackupPage;

/** The backup section served by the roblox-game-server demo's hilos index agent. */
final class BackupPage extends AbstractHilosBackupPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
