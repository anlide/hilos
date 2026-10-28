<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\ChangeLog;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableTriggersPage;

/**
 * ChangeLogTableTriggersPage - Hilos change log table triggers for the ecommerce-shop demo.
 */
final class ChangeLogTableTriggersPage extends AbstractHilosChangeLogTableTriggersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
