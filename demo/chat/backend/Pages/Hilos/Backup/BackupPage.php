<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Backup;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Backup\AbstractHilosBackupPage;

/**
 * BackupPage - Hilos backup page implementation for demo.
 */
final class BackupPage extends AbstractHilosBackupPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
