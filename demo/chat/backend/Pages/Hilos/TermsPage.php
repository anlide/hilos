<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosTermsPage;

/**
 * TermsPage - Terms page implementation for the chat demo.
 *
 * Static, content-only: the framework page sends no payload; the visible
 * content is the frontend view. Only the owning agent type is bound here.
 */
final class TermsPage extends AbstractHilosTermsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
