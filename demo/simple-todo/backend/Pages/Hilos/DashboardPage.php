<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Pages\Hilos;

use Demo\SimpleTodo\Constants\AgentType;
use Hilos\Pages\AbstractHilosDashboardPage;

/**
 * DashboardPage - Concrete Hilos dashboard page for the simple-todo demo.
 *
 * The application shell's gear links here; the framework base supplies the
 * subscription behavior and the browser snapshot, so the demo only binds the
 * page to its Hilos index agent.
 */
final class DashboardPage extends AbstractHilosDashboardPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
