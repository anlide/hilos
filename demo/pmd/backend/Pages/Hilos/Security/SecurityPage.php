<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos\Security;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityPage;

/**
 * SecurityPage - Security center overview for the PMD demo (HIL-286).
 */
final class SecurityPage extends AbstractHilosSecurityPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
