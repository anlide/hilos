<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * ModeratorPage - Moderator page handler.
 *
 * @property ModeratorAgent $agent
 */
final class ModeratorPage extends AbstractPage
{
    public const string PAGE = PageConstants::MODERATOR;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::MODERATOR;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_MODERATOR,
    ];
}
