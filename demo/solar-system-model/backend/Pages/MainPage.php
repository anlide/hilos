<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Pages;

use Demo\SolarSystemModel\Agents\SolarSystemModelAgent;
use Demo\SolarSystemModel\Constants\AgentType;
use Demo\SolarSystemModel\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * Solar System Model home subscription, with no project actions or payload.
 *
 * @property SolarSystemModelAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::SOLAR_SYSTEM_MODEL;
}
