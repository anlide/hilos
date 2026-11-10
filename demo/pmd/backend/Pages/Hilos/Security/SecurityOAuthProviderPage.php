<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos\Security;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthProviderPage;

/**
 * SecurityOAuthProviderPage - Single OAuth provider config for the PMD demo (HIL-286).
 */
final class SecurityOAuthProviderPage extends AbstractHilosSecurityOAuthProviderPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
