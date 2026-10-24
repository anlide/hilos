<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Daemon;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonNodeOverviewPage;

/** The addressed node overview, served by the project's Daemon mirror. */
final class DaemonNodePage extends AbstractHilosDaemonNodeOverviewPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
