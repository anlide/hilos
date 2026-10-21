<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\Communications;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;

/**
 * CommunicationsChannelPage - Single channel config for the ecommerce-shop demo.
 */
final class CommunicationsChannelPage extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
