<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos;

use Demo\Pmd\Agents\PmdAgent;
use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * PMD demo binding of the framework current-user profile page.
 *
 * Binds the PMD agent and its self-connection for the permitted live name.
 * Account writes remain on the users library; the profile is a reading surface.
 *
 * @property PmdAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PMD;
}
