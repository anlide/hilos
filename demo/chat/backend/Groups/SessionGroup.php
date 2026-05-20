<?php

declare(strict_types=1);

namespace Demo\Chat\Groups;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\GroupConstants;
use Hilos\Core\Group\AbstractGroup;

/**
 * Session group — broadcast scope for the active chat session.
 */
final class SessionGroup extends AbstractGroup
{
    public const string GROUP = GroupConstants::SESSION;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;
}
