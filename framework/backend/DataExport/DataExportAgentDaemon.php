<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/** The archive builder owns its worker: a whole archive may take seconds or longer. */
final class DataExportAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DATA_EXPORT;

    /**
     * @return bool True because archive assembly performs blocking filesystem work
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
