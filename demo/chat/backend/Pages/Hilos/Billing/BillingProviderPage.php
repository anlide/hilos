<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Billing;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Billing\AbstractHilosBillingProviderPage;

/**
 * BillingProviderPage - Single provider config for chat demo.
 */
final class BillingProviderPage extends AbstractHilosBillingProviderPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
