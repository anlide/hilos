<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

final class ChatSituationGuardianAgentDaemon extends AbstractAgentDaemon
{
    private const string AGENT_TYPE = AgentType::CHAT_SITUATION_GUARDIAN;

    public function __construct()
    {
        Logger::debug('ChatSituationGuardianAgentDaemon created [type=' . self::AGENT_TYPE . ']');
    }

    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    public function getIndex(): ?string
    {
        return null;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
