<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Pages\Hilos\Communications;

use Demo\HilosWebsite\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;

/**
 * CommunicationsDeliveriesPage - the delivery journal of the site's email channel.
 *
 * It stays empty: the site sends no notifications, and its codes are a raw send that writes no row.
 */
final class CommunicationsDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
