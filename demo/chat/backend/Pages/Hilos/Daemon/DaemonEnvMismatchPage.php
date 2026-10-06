<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;

/** DaemonEnvMismatchPage binds the framework Daemon page to this demo's page agent. */
final class DaemonEnvMismatchPage extends AbstractHilosDaemonEnvMismatchPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_DAEMON;
}
