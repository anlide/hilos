<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Security;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthPage;

/**
 * SecurityOAuthPage - OAuth providers list for chat demo.
 */
final class SecurityOAuthPage extends AbstractHilosSecurityOAuthPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
