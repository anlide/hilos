<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Security;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityPage;

/**
 * SecurityPage - Security center overview for chat demo.
 */
final class SecurityPage extends AbstractHilosSecurityPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
