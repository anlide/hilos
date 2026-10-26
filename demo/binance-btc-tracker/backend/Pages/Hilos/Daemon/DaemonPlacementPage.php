<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Daemon;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonPlacementPage;

final class DaemonPlacementPage extends AbstractHilosDaemonPlacementPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
