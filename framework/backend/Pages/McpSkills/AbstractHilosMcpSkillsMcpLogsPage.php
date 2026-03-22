<?php

declare(strict_types=1);

namespace Hilos\Pages\McpSkills;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosMcpSkillsMcpLogsPage - Abstract base for Hilos MCP log overview (usage stats).
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpLogsPage).
 */
abstract class AbstractHilosMcpSkillsMcpLogsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. mcpId)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP_LOGS,
            $acceptKey,
            new SignalData(),
        );
    }
}
