<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Security;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Security\AbstractHilosSecurityTwoFactorPage;

/**
 * SecurityTwoFactorPage - 2FA settings for chat demo.
 */
final class SecurityTwoFactorPage extends AbstractHilosSecurityTwoFactorPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
