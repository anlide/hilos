<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Security;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityImpersonationPage;

/**
 * SecurityImpersonationPage - impersonation settings for the tasks demo (HIL-1170).
 */
final class SecurityImpersonationPage extends AbstractHilosSecurityImpersonationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
