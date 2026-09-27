<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Legal;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalPage;

/** Binds the legal subscription to the demo's dedicated section agent. */
final class LegalPage extends AbstractHilosLegalPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
