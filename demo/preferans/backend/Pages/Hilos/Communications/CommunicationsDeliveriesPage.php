<?php

declare(strict_types=1);

namespace Demo\Preferans\Pages\Hilos\Communications;

use Demo\Preferans\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;

/**
 * CommunicationsDeliveriesPage - Channel deliveries stub for the preferans demo.
 */
final class CommunicationsDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
