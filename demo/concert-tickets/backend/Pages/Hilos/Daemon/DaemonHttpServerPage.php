<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\Daemon;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;

/** DaemonHttpServerPage binds the framework Daemon page to this demo's page agent. */
final class DaemonHttpServerPage extends AbstractHilosDaemonHttpServerPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
