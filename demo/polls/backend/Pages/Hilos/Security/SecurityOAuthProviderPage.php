<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Security;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthProviderPage;

/**
 * SecurityOAuthProviderPage - Single OAuth provider config for the polls demo (HIL-286).
 */
final class SecurityOAuthProviderPage extends AbstractHilosSecurityOAuthProviderPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
