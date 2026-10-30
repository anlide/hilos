<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\AbstractHilosLicenseDependenciesPage;

/** Binds the public dependency inventory page to the existing index agent. */
final class LicenseDependenciesPage extends AbstractHilosLicenseDependenciesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
