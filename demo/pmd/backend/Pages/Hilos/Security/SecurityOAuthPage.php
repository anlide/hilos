<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos\Security;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthPage;

/**
 * SecurityOAuthPage - OAuth providers list for the PMD demo (HIL-286).
 */
final class SecurityOAuthPage extends AbstractHilosSecurityOAuthPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
