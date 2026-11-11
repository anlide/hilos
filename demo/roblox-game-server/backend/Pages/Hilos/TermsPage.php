<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\AbstractHilosTermsPage;

/**
 * TermsPage - Terms page implementation for the roblox-game-server demo.
 *
 * The framework page owns its payload and the revision reads; only the owning
 * agent type is bound here.
 */
final class TermsPage extends AbstractHilosTermsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
