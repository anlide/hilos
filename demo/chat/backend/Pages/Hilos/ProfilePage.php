<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Chat demo binding of the framework current-user profile page.
 *
 * Binds the chat agent and its self-connection for the permitted live name.
 * Account writes remain on the users library; the profile is a reading surface.
 *
 * @property ChatAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;
}
