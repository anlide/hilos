<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Pages\Logs\AbstractHilosLogsPage;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/**
 * Abstract Hilos agent for the logs overview page (archive rotation metrics).
 *
 * Projects must extend this class with a concrete agent and register it in worker/daemon factories
 * and signal routing for {@see HilosPageConstants::HILOS_LOGS}, or omit the logs overview page.
 */
abstract class AbstractHilosLogsAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOGS;

    public function onTick(): void
    {
        AbstractHilosLogsPage::onAgentTick($this);
    }

    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        AbstractHilosLogsPage::removeSubscriber($data->acceptKey);
    }
}
