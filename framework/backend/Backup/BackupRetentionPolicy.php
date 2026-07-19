<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Hilos;

/**
 * BackupRetentionPolicy - the tunable knobs that drive backup rotation.
 *
 * The four tier depths are one shared set applied to every scope's grid (each scope
 * keeps its own independent {@see BackupPruner} grid of these depths); the error count
 * is a separate policy because error records never enter the restore grids. Depths are
 * plain counts, so a value of 0 disables that tier and negatives behave as 0.
 */
final class BackupRetentionPolicy
{
    /**
     * @param int $daily Buckets kept in the daily tier
     * @param int $weekly Buckets kept in the ISO-week tier
     * @param int $monthly Buckets kept in the calendar-month tier
     * @param int $yearly Buckets kept in the calendar-year tier
     * @param int $errorCount Newest error records kept
     */
    public function __construct(
        public readonly int $daily,
        public readonly int $weekly,
        public readonly int $monthly,
        public readonly int $yearly,
        public readonly int $errorCount,
    ) {
    }

    /**
     * Builds the policy from the backup retention env variables.
     *
     * @return self Policy seeded from env (catalog defaults: depths 45, error count 20)
     * @throws EnvInvalidValueException When a retention value is not a valid integer
     * @throws EnvKeyInvalidException When a retention key is invalid
     * @throws EnvNotInCatalogException When a retention key is not declared in the catalog
     * @throws EnvTypeMismatchException When a retention key is not cataloged as integer
     * @throws MissingEnvironmentVariableException When a required retention value is missing
     */
    public static function fromEnv(): self
    {
        return new self(
            Hilos::$env->int(EnvConstants::BACKUP_RETENTION_DAILY),
            Hilos::$env->int(EnvConstants::BACKUP_RETENTION_WEEKLY),
            Hilos::$env->int(EnvConstants::BACKUP_RETENTION_MONTHLY),
            Hilos::$env->int(EnvConstants::BACKUP_RETENTION_YEARLY),
            Hilos::$env->int(EnvConstants::BACKUP_ERROR_RETENTION_COUNT),
        );
    }
}
