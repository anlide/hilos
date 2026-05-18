<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\McpSkills;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\McpSkills\AbstractHilosMcpSkillsMcpLogsViewPage;

/**
 * McpSkillsMcpLogsViewPage - MCP log viewer for chat demo.
 */
final class McpSkillsMcpLogsViewPage extends AbstractHilosMcpSkillsMcpLogsViewPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
