<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Backup;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Backup\AbstractHilosBackupPage;

/**
 * BackupPage - Hilos backup page implementation for the tasks demo.
 */
final class BackupPage extends AbstractHilosBackupPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
