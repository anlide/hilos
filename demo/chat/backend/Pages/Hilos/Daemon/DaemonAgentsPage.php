<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonAgentsPage;

/**
 * DaemonAgentsPage - Hilos daemon agents list page implementation for demo.
 */
final class DaemonAgentsPage extends AbstractHilosDaemonAgentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
