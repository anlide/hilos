<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos\Security;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecuritySignInMethodsPage;

/**
 * SecuritySignInMethodsPage - sign-in methods for the PMD demo (HIL-427).
 */
final class SecuritySignInMethodsPage extends AbstractHilosSecuritySignInMethodsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
