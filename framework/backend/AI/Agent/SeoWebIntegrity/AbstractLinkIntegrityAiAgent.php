<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\SeoWebIntegrity;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractLinkIntegrityAiAgent - Project extension point for link integrity checks.
 */
abstract class AbstractLinkIntegrityAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::LINK_INTEGRITY;
}
