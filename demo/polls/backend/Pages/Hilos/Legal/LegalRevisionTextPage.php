<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Legal;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalRevisionTextPage;

/** Binds the legal text subscription to the demo's dedicated section agent. */
final class LegalRevisionTextPage extends AbstractHilosLegalRevisionTextPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
