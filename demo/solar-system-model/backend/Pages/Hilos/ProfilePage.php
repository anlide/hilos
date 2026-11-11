<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Pages\Hilos;

use Demo\SolarSystemModel\Agents\SolarSystemModelAgent;
use Demo\SolarSystemModel\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Solar System Model demo binding of the framework current-user profile page.
 *
 * Binds the Solar System Model agent that serves it. Account writes remain on the users
 * library; the profile is a reading surface.
 *
 * @property SolarSystemModelAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::SOLAR_SYSTEM_MODEL;
}
