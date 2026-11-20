<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Pages;

use Demo\HilosWebsite\Agents\HilosWebsiteAgent;
use Demo\HilosWebsite\Constants\AgentType;
use Demo\HilosWebsite\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * MainPage - The home page of the hilos-website demo.
 *
 * Declares no actions or signals: today the home is one heading with the name of the framework,
 * and nothing on it comes from the page. What it says about Hilos, the demo catalog and the video
 * arrive with a leaf of their own (HIL-2186).
 *
 * @property HilosWebsiteAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::WEBSITE;
}
