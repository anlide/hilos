<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\Communications;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsPage;

/**
 * CommunicationsPage - Hilos communications hub for the ecommerce-shop demo.
 */
final class CommunicationsPage extends AbstractHilosCommunicationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
