<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\ChangeLog;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablePage;

/**
 * ChangeLogTablePage - Hilos change log single-table detail for the ecommerce-shop demo.
 */
final class ChangeLogTablePage extends AbstractHilosChangeLogTablePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
