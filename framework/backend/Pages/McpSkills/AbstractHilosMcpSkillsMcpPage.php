<?php

declare(strict_types=1);

namespace Hilos\Pages\McpSkills;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosMcpSkillsMcpPage - Abstract base for Hilos single MCP detail page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpPage).
 */
abstract class AbstractHilosMcpSkillsMcpPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MCP_SKILLS_MCP;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP,
    ];
}
