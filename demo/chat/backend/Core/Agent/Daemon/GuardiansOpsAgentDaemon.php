<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

final class GuardiansOpsAgentDaemon extends AbstractAgentDaemon
{
    private const string AGENT_TYPE = AgentType::GUARDIAN_OPS;

    public function __construct()
    {
        Logger::debug('GuardiansOpsAgentDaemon created [type=' . self::AGENT_TYPE . ']');
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
