<?php

declare(strict_types=1);

namespace Hilos\Pages\McpSkills;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosMcpSkillsMcpLogsPage - Abstract base for Hilos MCP log overview (usage stats).
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpLogsPage).
 */
abstract class AbstractHilosMcpSkillsMcpLogsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP_LOGS,
    ];
}
