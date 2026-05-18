<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Security;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthProviderPage;

/**
 * SecurityOAuthProviderPage - Single OAuth provider for chat demo.
 */
final class SecurityOAuthProviderPage extends AbstractHilosSecurityOAuthProviderPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
