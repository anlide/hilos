<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\McpSkills;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\McpSkills\AbstractHilosMcpSkillsDashboardPage;

/**
 * McpSkillsDashboardPage - MCP and Skills hub for chat demo.
 */
final class McpSkillsDashboardPage extends AbstractHilosMcpSkillsDashboardPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
