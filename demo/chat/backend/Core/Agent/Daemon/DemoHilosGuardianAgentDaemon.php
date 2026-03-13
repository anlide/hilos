<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * DemoHilosGuardianAgentDaemon - Daemon proxy for DemoHilosGuardianAgent
 */
class DemoHilosGuardianAgentDaemon extends AbstractAgentDaemon
{
    public function __construct()
    {
        Logger::debug("DemoHilosGuardianAgentDaemon created [type=" . HilosAgentType::HILOS_GUARDIAN . "]");
    }

    public function getType(): string
    {
        return HilosAgentType::HILOS_GUARDIAN;
    }

    public function getIndex(): ?string
    {
        return null;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }

    public function handleWorkerMessage(array $data): void
    {
    }

    public function handleExternalMessage(array $data): ?array
    {
        return null;
    }
}
