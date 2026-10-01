<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Security;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityStepUpPage;

/**
 * SecurityStepUpPage - operations that ask for confirmation for the polls demo (HIL-1204).
 */
final class SecurityStepUpPage extends AbstractHilosSecurityStepUpPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
