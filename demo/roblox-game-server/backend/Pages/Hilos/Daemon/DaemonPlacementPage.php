<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Daemon;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonPlacementPage;

final class DaemonPlacementPage extends AbstractHilosDaemonPlacementPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
