<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos\Communications;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;

/**
 * CommunicationsDeliveriesPage - Channel deliveries stub for the PMD demo.
 */
final class CommunicationsDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
