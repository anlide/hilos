<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Pages\Hilos;

use Demo\SolarSystemModel\Constants\AgentType;
use Hilos\Pages\AbstractHilosTermsPage;

/**
 * TermsPage - Terms page implementation for the Solar System Model demo.
 *
 * The framework page owns its payload and the revision reads; only the owning
 * agent type is bound here.
 */
final class TermsPage extends AbstractHilosTermsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
