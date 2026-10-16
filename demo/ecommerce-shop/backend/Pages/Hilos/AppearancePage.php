<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Appearance\AbstractHilosAppearancePage;

/** Binds the framework Appearance page to the ecommerce-shop index agent. */
final class AppearancePage extends AbstractHilosAppearancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
