<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\ChangeLog;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogFeedPage;

/**
 * ChangeLogFeedPage - Hilos change log feed for chat demo.
 */
final class ChangeLogFeedPage extends AbstractHilosChangeLogFeedPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
