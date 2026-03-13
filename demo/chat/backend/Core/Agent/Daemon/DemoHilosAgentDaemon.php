<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * DemoHilosAgentDaemon - Daemon proxy for DemoHilosAgent (index/dashboard/settings/i18n)
 */
class DemoHilosAgentDaemon extends AbstractAgentDaemon
{
    public function __construct()
    {
        Logger::debug("DemoHilosAgentDaemon created [type=" . HilosAgentType::HILOS_INDEX . "]");
    }

    public function getType(): string
    {
        return HilosAgentType::HILOS_INDEX;
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
