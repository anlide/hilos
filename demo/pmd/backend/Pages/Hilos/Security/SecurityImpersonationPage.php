<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos\Security;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityImpersonationPage;

/**
 * SecurityImpersonationPage - impersonation settings for the PMD demo (HIL-1170).
 */
final class SecurityImpersonationPage extends AbstractHilosSecurityImpersonationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
