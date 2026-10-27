<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\ChangeLog;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogNoisyPage;

/**
 * ChangeLogNoisyPage - Hilos change log noisy for the polls demo.
 */
final class ChangeLogNoisyPage extends AbstractHilosChangeLogNoisyPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
