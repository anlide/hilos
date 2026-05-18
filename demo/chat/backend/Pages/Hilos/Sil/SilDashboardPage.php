<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Sil;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Sil\AbstractHilosSilDashboardPage;

/**
 * SilDashboardPage - System Intelligence Layer dashboard for chat demo.
 */
final class SilDashboardPage extends AbstractHilosSilDashboardPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
