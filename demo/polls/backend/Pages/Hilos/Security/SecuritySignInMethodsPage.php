<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Security;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecuritySignInMethodsPage;

/**
 * SecuritySignInMethodsPage - sign-in methods for the polls demo (HIL-427).
 */
final class SecuritySignInMethodsPage extends AbstractHilosSecuritySignInMethodsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
