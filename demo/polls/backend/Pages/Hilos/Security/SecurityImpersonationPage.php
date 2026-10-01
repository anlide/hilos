<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Security;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityImpersonationPage;

/**
 * SecurityImpersonationPage - impersonation settings for the polls demo (HIL-1170).
 */
final class SecurityImpersonationPage extends AbstractHilosSecurityImpersonationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
