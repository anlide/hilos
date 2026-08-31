<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * AdminPage - Admin page handler.
 *
 * @property ChatAgent $agent
 */
final class AdminPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN,
    ];
}
