<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\ChangeLog;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogFeedPage;

/**
 * ChangeLogFeedPage - Hilos change log feed for the online-testing demo.
 */
final class ChangeLogFeedPage extends AbstractHilosChangeLogFeedPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
