<?php

declare(strict_types=1);

namespace Demo\Preferans\Pages;

use Demo\Preferans\Agents\PreferansAgent;
use Demo\Preferans\Constants\AgentType;
use Demo\Preferans\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * MainPage - The home page of the preferans demo.
 *
 * Declares no actions or signals: the home says who is looking and nothing more, and the name
 * it shows comes with the session identity every page already has. The list of tests this page
 * becomes is drawn in the demo's mockup and arrives with leaves of its own.
 *
 * @property PreferansAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PREFERANS;
}
