<?php

declare(strict_types=1);

namespace Hilos\Pages\McpSkills;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosMcpSkillsDashboardPage - Abstract base for Hilos MCP and Skills hub.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\McpSkills\McpSkillsDashboardPage).
 */
abstract class AbstractHilosMcpSkillsDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MCP_SKILLS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS,
            $acceptKey,
            new SignalData(),
        );
    }
}
