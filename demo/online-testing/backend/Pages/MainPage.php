<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages;

use Demo\OnlineTesting\Agents\OnlineTestingAgent;
use Demo\OnlineTesting\Constants\AgentType;
use Demo\OnlineTesting\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * MainPage - The home page of the online-testing demo.
 *
 * Declares no actions or signals: the home says who is looking and nothing more, and the name
 * it shows comes with the session identity every page already has. The list of tests this page
 * becomes is drawn in the demo's mockup and arrives with leaves of its own.
 *
 * @property OnlineTestingAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ONLINE_TESTING;
}
