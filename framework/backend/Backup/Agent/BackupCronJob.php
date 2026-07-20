<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent;

use Hilos\Backup\BackupScope;
use Hilos\Core\Daemon\Cron\CronRule;

/**
 * BackupCronJob - an agent-mechanism schedule entry the backup agent evaluates itself.
 *
 * Pairs the cron rule the agent ticks with the scope it creates when the rule fires. The
 * agent builds one per agent-mechanism schedule entry on start and checks them each tick.
 */
final class BackupCronJob
{
    /**
     * @param CronRule $rule Cron rule evaluated each tick
     * @param BackupScope $scope Scope to create when the rule fires
     */
    public function __construct(
        public readonly CronRule $rule,
        public readonly BackupScope $scope,
    ) {
    }
}
