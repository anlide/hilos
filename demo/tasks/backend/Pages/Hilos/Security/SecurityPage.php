<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Security;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityPage;

/**
 * SecurityPage - Security center overview for the tasks demo (HIL-286).
 */
final class SecurityPage extends AbstractHilosSecurityPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
