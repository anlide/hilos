<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\Daemon;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonPlacementPage;

final class DaemonPlacementPage extends AbstractHilosDaemonPlacementPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
