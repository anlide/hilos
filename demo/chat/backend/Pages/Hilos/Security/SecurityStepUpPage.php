<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Security;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityStepUpPage;

/**
 * SecurityStepUpPage - operations that ask for confirmation for chat demo (HIL-1204).
 */
final class SecurityStepUpPage extends AbstractHilosSecurityStepUpPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
