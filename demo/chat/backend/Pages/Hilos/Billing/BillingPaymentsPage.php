<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Billing;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Billing\AbstractHilosBillingPaymentsPage;

/**
 * BillingPaymentsPage - Payments list for chat demo.
 */
final class BillingPaymentsPage extends AbstractHilosBillingPaymentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
