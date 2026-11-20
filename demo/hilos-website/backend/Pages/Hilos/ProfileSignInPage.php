<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Pages\Hilos;

use Demo\HilosWebsite\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileSignInPage;

/**
 * Binds the framework's sign-in methods page to the hilos-website agent.
 *
 * Registration is closed, so an account made by admin:create gets its password here. The site
 * links no OAuth provider, so the framework's default wiring stays: a link start is refused.
 */
final class ProfileSignInPage extends AbstractHilosProfileSignInPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::WEBSITE;
}
