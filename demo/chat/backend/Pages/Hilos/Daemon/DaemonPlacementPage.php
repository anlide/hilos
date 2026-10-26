<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonPlacementPage;

final class DaemonPlacementPage extends AbstractHilosDaemonPlacementPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
