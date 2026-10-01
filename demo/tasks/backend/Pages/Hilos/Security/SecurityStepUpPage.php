<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Security;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityStepUpPage;

/**
 * SecurityStepUpPage - operations that ask for confirmation for the tasks demo (HIL-1204).
 */
final class SecurityStepUpPage extends AbstractHilosSecurityStepUpPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
