<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Legal;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalRevisionPage;

/** Binds the legal subscription to the demo's dedicated section agent. */
final class LegalRevisionPage extends AbstractHilosLegalRevisionPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
