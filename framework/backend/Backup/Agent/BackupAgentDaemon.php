<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * BackupAgentDaemon - daemon proxy for the monopoly backup agent.
 */
final class BackupAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_BACKUP;

    /**
     * Backup runs as a single owner of the backup index and storage.
     *
     * @return bool True because the backup agent is monopolistic
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
