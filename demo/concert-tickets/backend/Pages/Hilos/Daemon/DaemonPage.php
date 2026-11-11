<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\Daemon;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;

/** DaemonPage binds the framework Daemon page to this demo's page agent. */
final class DaemonPage extends AbstractHilosDaemonPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
