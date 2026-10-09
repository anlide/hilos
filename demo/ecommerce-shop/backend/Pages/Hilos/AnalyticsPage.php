<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the ecommerce-shop demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
