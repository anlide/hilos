<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Communications;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;

/**
 * CommunicationsDeliveriesPage - Channel deliveries stub for chat demo.
 */
final class CommunicationsDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
