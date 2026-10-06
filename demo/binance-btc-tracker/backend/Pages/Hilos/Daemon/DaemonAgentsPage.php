<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Daemon;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonAgentsPage;

/** DaemonAgentsPage binds the framework Daemon page to this demo's page agent. */
final class DaemonAgentsPage extends AbstractHilosDaemonAgentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
