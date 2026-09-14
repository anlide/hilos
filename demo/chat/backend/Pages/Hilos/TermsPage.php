<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosTermsPage;

/**
 * TermsPage - Terms page implementation for the chat demo.
 *
 * The framework page sends no payload; only the owning agent type is bound
 * here.
 */
final class TermsPage extends AbstractHilosTermsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
