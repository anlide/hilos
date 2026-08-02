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
 * BackupSpacePolicy - the tunable knobs that drive the pre-run free-space gate.
 *
 * Modelled on {@see BackupRetentionPolicy}: three env numbers read once through {@see fromEnv()}
 * and handed to the pure {@see BackupSpaceGuard}. The margin pads the estimate so a run is refused
 * with headroom; the floor is an absolute free-space minimum checked on every run, estimate or not;
 * the refuse-without-estimate flag decides what to do the first time a scope is backed up, before
 * any successful run has recorded a dump size to size from.
 */
final class BackupSpacePolicy
{
    /**
     * @param float $spaceMargin Multiplier applied to the estimated uncompressed peak
     * @param int $minFreeBytes Absolute free-space floor in bytes, checked on every run
     * @param bool $refuseWithoutEstimate Whether to refuse a run when no prior run sizes the scope
     */
    public function __construct(
        public readonly float $spaceMargin,
        public readonly int $minFreeBytes,
        public readonly bool $refuseWithoutEstimate,
    ) {
    }

    /**
     * Builds the policy from the backup space env variables.
     *
     * @return self Policy seeded from env (catalog defaults: margin 1.5, floor 1 GiB, refuse false)
     * @throws EnvInvalidValueException When a value is not a valid number/bool
     * @throws EnvKeyInvalidException When a key is invalid
     * @throws EnvNotInCatalogException When a key is not declared in the catalog
     * @throws EnvTypeMismatchException When a key is cataloged as the wrong type
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public static function fromEnv(): self
    {
        return new self(
            Hilos::$env->float(EnvConstants::BACKUP_SPACE_MARGIN),
            Hilos::$env->int(EnvConstants::BACKUP_MIN_FREE_BYTES),
            Hilos::$env->bool(EnvConstants::BACKUP_REFUSE_WITHOUT_ESTIMATE),
        );
    }
}
