<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\ChangeLog;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogNoisyPage;

/**
 * ChangeLogNoisyPage - Hilos change log noisy for the ecommerce-shop demo.
 */
final class ChangeLogNoisyPage extends AbstractHilosChangeLogNoisyPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
