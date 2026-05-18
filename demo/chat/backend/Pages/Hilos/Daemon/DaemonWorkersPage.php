<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;

/**
 * DaemonWorkersPage - Hilos daemon workers list page implementation for demo.
 */
final class DaemonWorkersPage extends AbstractHilosDaemonWorkersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
