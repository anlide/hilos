<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonWebsocketsPage;

/**
 * DaemonWebsocketsPage - Hilos daemon websocket connections page implementation for demo.
 */
final class DaemonWebsocketsPage extends AbstractHilosDaemonWebsocketsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
