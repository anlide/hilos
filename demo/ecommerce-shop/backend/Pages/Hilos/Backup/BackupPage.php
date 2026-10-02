<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\Backup;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Backup\AbstractHilosBackupPage;

/** The backup section served by this demo's hilos index agent. */
final class BackupPage extends AbstractHilosBackupPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
