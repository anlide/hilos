<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;

/**
 * DaemonHttpServerPage - Hilos daemon HTTP server detail page implementation for demo.
 */
final class DaemonHttpServerPage extends AbstractHilosDaemonHttpServerPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
