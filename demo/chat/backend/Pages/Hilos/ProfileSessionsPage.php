<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Database\ChatDbContext;
use Hilos\Pages\AbstractHilosProfileSessionsPage;

/**
 * Chat binding of the framework current-user sessions page.
 *
 * @property ChatAgent $agent
 */
final class ProfileSessionsPage extends AbstractHilosProfileSessionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;
    public const array READS_DB = [ChatDbContext::sessions];
}
