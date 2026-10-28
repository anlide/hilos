<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\Status;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;

/** Status subscriber window served by the project's index agent. */
final class StatusSubscribersPage extends AbstractHilosStatusSubscribersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
