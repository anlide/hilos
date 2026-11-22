<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Pages;

use Demo\HilosWebsite\Agents\HilosWebsiteAgent;
use Demo\HilosWebsite\Constants\AgentType;
use Demo\HilosWebsite\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * HistoryPage - The public history of Hilos on the hilos-website demo.
 *
 * Declares no actions, signals or data: the whole text lives in the frontend and is prerendered
 * into history.html, so the subscription only tells the client the page exists.
 *
 * @property HilosWebsiteAgent $agent
 */
final class HistoryPage extends AbstractPage
{
    public const string PAGE = PageConstants::HISTORY;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::WEBSITE;
}
