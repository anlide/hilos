<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Pages\Logs\AbstractHilosLogsPage;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/**
 * AbstractHilosIndexAgent - Abstract agent for Hilos dashboard, settings and i18n pages.
 *
 * Projects must extend this class to provide a concrete agent for Hilos index pages
 * (dashboard, settings, i18n). If not extended, the corresponding pages will not function.
 */
abstract class AbstractHilosIndexAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    public function onTick(): void
    {
        AbstractHilosLogsPage::onAgentTick($this);
    }

    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        AbstractHilosLogsPage::removeSubscriber($data->acceptKey);
    }
}
