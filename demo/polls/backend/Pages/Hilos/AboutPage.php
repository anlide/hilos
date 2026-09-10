<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\AbstractHilosAboutPage;

/**
 * AboutPage - About page implementation for the polls demo.
 *
 * The framework page sends no payload; what is on screen is the frontend view -
 * this project's prose with the framework's support block at the end of it. Only
 * the owning agent type is bound here.
 */
final class AboutPage extends AbstractHilosAboutPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
