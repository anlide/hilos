<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Billing;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Billing\AbstractHilosBillingRefundsPage;

/**
 * BillingRefundsPage - Refunds list for chat demo.
 */
final class BillingRefundsPage extends AbstractHilosBillingRefundsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
