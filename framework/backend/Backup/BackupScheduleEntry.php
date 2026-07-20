<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupScheduleException;

/**
 * BackupScheduleEntry - one scheduled backup: a name, a cron expression, a scope, a mechanism.
 *
 * A validated, immutable projection of one project catalog schedule entry. The name is the
 * unique job identifier (and, for the daemon mechanism, the DAEMON/CRON signal name); the
 * cron expression is the standard five-field form evaluated in the server timezone; the
 * scope is what the run captures; the mechanism is which evaluator owns it.
 */
final class BackupScheduleEntry
{
    /**
     * @param string $name Unique job name
     * @param string $expression Five-field cron expression (server timezone)
     * @param BackupScope $scope Scope the run captures
     * @param BackupScheduleMechanism $mechanism Evaluator that owns the entry
     */
    public function __construct(
        public readonly string $name,
        public readonly string $expression,
        public readonly BackupScope $scope,
        public readonly BackupScheduleMechanism $mechanism,
    ) {
    }

    /**
     * Builds and validates one entry from a raw catalog schedule row.
     *
     * @param array<string, mixed> $raw Raw catalog schedule entry
     * @return self Validated schedule entry
     * @throws BackupScheduleException When the name/cron is missing or empty, or the scope
     *     or mechanism value is unknown
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

        return new self($name, $expression, $scope, $mechanism);
    }
}
