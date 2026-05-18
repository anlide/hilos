<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;

/**
 * DaemonCronPage - Hilos daemon cron list page implementation for demo.
 */
final class DaemonCronPage extends AbstractHilosDaemonCronPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
