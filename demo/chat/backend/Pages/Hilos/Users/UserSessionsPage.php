<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Users\AbstractHilosUserSessionsPage;

/**
 * Chat binding of the Sessions tab of a person's card (HIL-1662).
 *
 * The framework page owns the subscription, the sessions list it requires, and the two endings it
 * hands to the sessions library; the chat names only the agent serving it, the same index agent
 * that serves the card's Overview ({@see UserPage}).
 */
final class UserSessionsPage extends AbstractHilosUserSessionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
