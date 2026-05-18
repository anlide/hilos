<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Roles;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Roles\AbstractHilosRolesPage;

/**
 * RolesPage - Hilos roles list page implementation for demo.
 */
final class RolesPage extends AbstractHilosRolesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
