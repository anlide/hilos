<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Daemon;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvPage;

/** DaemonEnvPage binds the framework Daemon page to this demo's page agent. */
final class DaemonEnvPage extends AbstractHilosDaemonEnvPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
