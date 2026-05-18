<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\McpSkills;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\McpSkills\AbstractHilosMcpSkillsMcpPage;

/**
 * McpSkillsMcpPage - Single MCP detail page for chat demo.
 */
final class McpSkillsMcpPage extends AbstractHilosMcpSkillsMcpPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
