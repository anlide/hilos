<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\Communications;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;

/** Email letter frame subscription served by the ecommerce-shop demo's index agent. */
final class CommunicationsChannelFramePage extends AbstractHilosCommunicationsChannelFramePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
