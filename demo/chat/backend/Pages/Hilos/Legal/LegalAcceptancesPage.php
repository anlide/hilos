<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Legal;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalAcceptancesPage;

/** Binds the legal subscription to the demo's dedicated section agent. */
final class LegalAcceptancesPage extends AbstractHilosLegalAcceptancesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
