<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\AbstractHilosPrivacyPage;

/**
 * PrivacyPage - Privacy page implementation for the tasks demo.
 *
 * The framework page sends no payload; only the owning agent type is bound
 * here.
 */
final class PrivacyPage extends AbstractHilosPrivacyPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
