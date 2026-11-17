<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Ecommerce Shop binding of the framework current-user profile page.
 *
 * Binds the app agent that serves it. Account writes remain on the users library; the profile
 * is a reading surface.
 *
 * @property EcommerceShopAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ECOMMERCE_SHOP;
}
