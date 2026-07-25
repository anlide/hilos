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
 * Each of the four tiers is an **age at which its granularity starts applying**, counted
 * in that tier's own unit: with the defaults, a backup younger than 45 days is never
 * thinned, from 45 days on only the newest of each day survives, from 45 weeks on only
 * the newest of each week, from 45 months on the newest of each month, and past 45 years
 * the newest of each year. The ladder is read in that order, so the numbers are meant to
 * describe increasing ages; a tier set to 0 simply starts its granularity at once.
 *
 * The four ages are one shared set applied to every scope's grid (each scope keeps its
 * own independent {@see BackupPruner} grid); the error count is separate, a plain count,
 * because error records carry no restore value and never enter the grids.
 */
final class BackupRetentionPolicy
{
    /**
     * @param int $daily Age in days from which only the newest backup of each day is kept
     * @param int $weekly Age in weeks from which only the newest backup of each ISO week is kept
     * @param int $monthly Age in months from which only the newest backup of each month is kept
     * @param int $yearly Age in years from which only the newest backup of each year is kept
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
     * @return self Policy seeded from env (catalog defaults: 45 of each unit, error count 20)
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
