<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Backup;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Backup\AbstractHilosBackupPage;

/** The backup section served by the binance-btc-tracker demo's hilos index agent. */
final class BackupPage extends AbstractHilosBackupPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
