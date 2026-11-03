<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Hilos\Backup\BackupSchedule as CronRule;
use Hilos\Core\Daemon\Cron\CronRuleOrigin;

/**
 * Look-alikes CRON-NAMESPACE must leave silent under a framework root and under a project root.
 *
 * CronRule here is the backup schedule, not a cron rule. BackupScheduleEntry is a class of this
 * file, not the framework entry. A string, a comment and a call that is not a construction wear
 * the same words and build nothing.
 */
final class CronNamespaceLookAlikes
{
    /**
     * @param string $name A label that is not a cron rule name
     * @param string $expression Text that is not read as code
     * @param mixed $origin A value that is not an origin of a cron rule
     */
    public function samples(string $name, string $expression, mixed $origin): void
    {
        new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK);
        new BackupScheduleEntry($name, $expression, $origin, $origin, CronRuleOrigin::FRAMEWORK);
        $quoted = 'new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK)';
        // new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK)
        CronRule($name, $expression, CronRuleOrigin::FRAMEWORK);
        $marker = CronRuleOrigin::FRAMEWORK;
        $schedule = $marker instanceof CronRule;
    }
}

/**
 * Same short name as the framework schedule entry, and a different class.
 */
class BackupScheduleEntry
{
    /**
     * @param string $name Entry name
     * @param string $expression Schedule text
     * @param mixed $scope Scope stand-in
     * @param mixed $mechanism Mechanism stand-in
     * @param mixed $origin Origin stand-in
     */
    public function __construct(
        string $name,
        string $expression,
        mixed $scope,
        mixed $mechanism,
        mixed $origin = null,
    ) {
    }
}
