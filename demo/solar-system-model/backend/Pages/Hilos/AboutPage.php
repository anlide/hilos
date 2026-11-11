<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Pages\Hilos;

use Demo\SolarSystemModel\Constants\AgentType;
use Hilos\Pages\AbstractHilosAboutPage;

/**
 * AboutPage - About page implementation for the Solar System Model demo.
 *
 * The framework page sends no payload; only the owning agent type is bound
 * here.
 */
final class AboutPage extends AbstractHilosAboutPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
