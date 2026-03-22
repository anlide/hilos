<?php

declare(strict_types=1);

namespace Hilos\Pages\McpSkills;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosMcpSkillsMcpPage - Abstract base for Hilos single MCP detail page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpPage).
 */
abstract class AbstractHilosMcpSkillsMcpPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MCP_SKILLS_MCP;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. mcpId)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP,
            $acceptKey,
            new SignalData(),
        );
    }
}
