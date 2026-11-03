<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupScheduleException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\CronRuleOrigin;
use Hilos\Core\Daemon\Cron\Exception\CronRuleNameException;

/**
 * BackupScheduleEntry - one scheduled backup: a name, a cron expression, a scope, a mechanism,
 * and who declared the name.
 *
 * A validated, immutable projection of one project catalog schedule entry. The name is the
 * unique job identifier (and, for the daemon mechanism, the DAEMON/CRON signal name); the
 * cron expression is the standard five-field form evaluated in the project time zone; the
 * scope is what the run captures; the mechanism is which evaluator owns it.
 */
final class BackupScheduleEntry
{
    /**
     * @param string $name Unique job name
     * @param string $expression Five-field cron expression (project time zone)
     * @param BackupScope $scope Scope the run captures
     * @param BackupScheduleMechanism $mechanism Evaluator that owns the entry
     * @param CronRuleOrigin $origin Who declared the name; a raw catalog row stays project
     * @throws CronRuleNameException When a project name uses the reserved prefix or a framework name lacks it
     */
    public function __construct(
        public readonly string $name,
        public readonly string $expression,
        public readonly BackupScope $scope,
        public readonly BackupScheduleMechanism $mechanism,
        public readonly CronRuleOrigin $origin = CronRuleOrigin::PROJECT,
    ) {
        CronRule::assertNameOrigin($name, $origin);
    }

    /**
     * Builds and validates one entry from a raw catalog schedule row.
     *
     * @param array<string, mixed> $raw Raw catalog schedule entry
     * @return self Validated schedule entry
     * @throws BackupScheduleException When the name/cron is missing or empty, the name is reserved
     *     for the framework, or the scope or mechanism value is unknown
     */
    public static function fromArray(array $raw): self
    {
        $name = $raw[BackupConstants::SCHEDULE_NAME] ?? null;
        if (!is_string($name) || $name === '') {
            throw new BackupScheduleException('Backup schedule entry is missing a non-empty name');
        }

        $expression = $raw[BackupConstants::SCHEDULE_CRON] ?? null;
        if (!is_string($expression) || $expression === '') {
            throw new BackupScheduleException("Backup schedule entry '{$name}' is missing a non-empty cron expression");
        }

        $scopeValue = $raw[BackupConstants::SCHEDULE_SCOPE] ?? null;
        $scope = BackupScope::fromString(is_string($scopeValue) ? $scopeValue : null);
        if ($scope === null) {
            throw new BackupScheduleException("Backup schedule entry '{$name}' has an unknown scope");
        }

        $mechanismValue = $raw[BackupConstants::SCHEDULE_MECHANISM] ?? null;
        $mechanism = BackupScheduleMechanism::fromString(is_string($mechanismValue) ? $mechanismValue : null);
        if ($mechanism === null) {
            throw new BackupScheduleException("Backup schedule entry '{$name}' has an unknown mechanism");
        }

        try {
            return new self($name, $expression, $scope, $mechanism);
        } catch (CronRuleNameException $exception) {
            throw new BackupScheduleException($exception->getMessage(), previous: $exception);
        }
    }
}
