<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the ecommerce-shop agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ECOMMERCE_SHOP;
}
