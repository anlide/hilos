<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\AbstractHilosPrivacyPage;

/**
 * PrivacyPage - Privacy page implementation for the online-testing demo.
 *
 * The framework page sends no payload; only the owning agent type is bound
 * here.
 */
final class PrivacyPage extends AbstractHilosPrivacyPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
