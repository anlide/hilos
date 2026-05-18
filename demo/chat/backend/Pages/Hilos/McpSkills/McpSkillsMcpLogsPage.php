<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\McpSkills;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\McpSkills\AbstractHilosMcpSkillsMcpLogsPage;

/**
 * McpSkillsMcpLogsPage - MCP usage / log overview for chat demo.
 */
final class McpSkillsMcpLogsPage extends AbstractHilosMcpSkillsMcpLogsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
