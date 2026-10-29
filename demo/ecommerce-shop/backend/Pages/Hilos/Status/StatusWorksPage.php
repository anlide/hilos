<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\Status;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;

/** Status work window served by the project's index agent. */
final class StatusWorksPage extends AbstractHilosStatusWorksPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
