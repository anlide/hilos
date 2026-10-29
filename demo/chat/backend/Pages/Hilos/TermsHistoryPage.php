<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosTermsHistoryPage;

/** Binds the public Terms history page to the index agent. */
final class TermsHistoryPage extends AbstractHilosTermsHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
