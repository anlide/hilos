<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Billing;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Billing\AbstractHilosBillingPage;

/**
 * BillingPage - Payment providers hub for chat demo.
 */
final class BillingPage extends AbstractHilosBillingPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
