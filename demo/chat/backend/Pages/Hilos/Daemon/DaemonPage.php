<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;

/**
 * DaemonPage - Hilos daemon dashboard page implementation for demo.
 */
final class DaemonPage extends AbstractHilosDaemonPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
