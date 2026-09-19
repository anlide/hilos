<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Security;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecuritySignInMethodsPage;

/**
 * SecuritySignInMethodsPage - sign-in methods for the tasks demo (HIL-427).
 */
final class SecuritySignInMethodsPage extends AbstractHilosSecuritySignInMethodsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
