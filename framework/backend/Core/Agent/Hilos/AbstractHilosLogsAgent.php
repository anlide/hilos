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

    /**
     * Delegates the per-tick logs overview refresh to the logs page.
     */
    public function onTick(): void
    {
        AbstractHilosLogsPage::onAgentTick($this);
    }

    /**
     * Drops the closed connection from the logs overview subscriber set.
     *
     * @param WebSocketCloseSignalDTO $data Close signal payload (carries the acceptKey)
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        AbstractHilosLogsPage::removeSubscriber($data->acceptKey);
    }
}
