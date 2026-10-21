<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;

/**
 * Ecommerce-shop binding of the framework current-user notification channels page.
 *
 * @property EcommerceShopAgent $agent
 */
final class ProfileNotificationsPage extends AbstractHilosProfileNotificationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ECOMMERCE_SHOP;
}
