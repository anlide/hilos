<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\Communications;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;

/**
 * CommunicationsDeliveriesPage - Channel deliveries stub for the online-testing demo.
 */
final class CommunicationsDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
