<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Users;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Users\AbstractHilosUserSessionsPage;

/**
 * Roblox Game Server binding of the Sessions tab of a person's card (HIL-1662).
 *
 * The framework page owns the subscription, the sessions list it requires, and the two endings it
 * hands to the sessions library; this demo names only the agent serving it, the same index agent
 * that serves the card's Overview ({@see UserPage}).
 */
final class UserSessionsPage extends AbstractHilosUserSessionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
