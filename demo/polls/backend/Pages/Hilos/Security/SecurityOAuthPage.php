<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Security;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthPage;

/**
 * SecurityOAuthPage - OAuth providers list for the polls demo (HIL-286).
 */
final class SecurityOAuthPage extends AbstractHilosSecurityOAuthPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
