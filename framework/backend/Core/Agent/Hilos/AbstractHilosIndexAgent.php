<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Database\Context\HilosDbContext;

/**
 * AbstractHilosIndexAgent - Abstract agent for Hilos dashboard, settings, i18n, and non-logs admin pages.
 *
 * Projects must extend this class to provide a concrete agent for Hilos index-scoped pages.
 * Logs overview uses {@see AbstractHilosLogsAgent} separately.
 */
abstract class AbstractHilosIndexAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /**
     * Registers framework settings as the Hilos index DB truth source.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(HilosDbContext::settings);
    }
}
