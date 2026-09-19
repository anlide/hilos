<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Security;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityTwoFactorPage;

/**
 * SecurityTwoFactorPage - 2FA settings for the polls demo (HIL-286).
 */
final class SecurityTwoFactorPage extends AbstractHilosSecurityTwoFactorPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
