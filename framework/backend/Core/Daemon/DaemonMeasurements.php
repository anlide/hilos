<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Exception\InvalidFormatException;

/** The master's latest node and process measurements. Null means the value is unknown. */
final readonly class DaemonMeasurements
{
    public const string CORES_QUOTA = 'quota';
    public const string CORES_CPUS = 'cpus';
    public const string CEILING_CGROUP = 'cgroup';
    public const string CEILING_MACHINE = 'machine';

    private const int BYTES_PER_MIB = 1048576;

    /**
     * @param int $startedAt Master start time as Unix seconds
     * @param ?float $cores Effective node CPU cores
     * @param ?string $coresBound Source limiting CPU cores
     * @param ?float $daemonCores Occupied cores across master and workers
     * @param ?float $masterCores Occupied cores of the master
     * @param ?int $machineLoad Aggregate machine load percentage
     * @param ?int $masterMemory Master RSS floored to whole MiB
     * @param ?int $workersMemory Worker RSS sum floored to whole MiB
     * @param int $workersMeasured Workers included in the RSS sum
     * @param ?int $memoryCeiling Effective memory ceiling in bytes
     * @param ?string $memoryCeilingBound Source limiting memory
     * @param ?int $phpMemoryLimit Per-process PHP memory limit in bytes
     * @throws InvalidFormatException When a measurement or its source label violates the contract
     */
    public function __construct(
        public int $startedAt,
        public ?float $cores,
        public ?string $coresBound,
        public ?float $daemonCores,
        public ?float $masterCores,
        public ?int $machineLoad,
        public ?int $masterMemory,
        public ?int $workersMemory,
        public int $workersMeasured,
        public ?int $memoryCeiling,
        public ?string $memoryCeilingBound,
        public ?int $phpMemoryLimit,
    ) {
        if ($startedAt <= 0
            || ($cores === null) !== ($coresBound === null)
            || ($cores !== null && (!is_finite($cores) || $cores <= 0 || round($cores, 2) !== $cores))
            || ($coresBound !== null && !in_array($coresBound, [self::CORES_QUOTA, self::CORES_CPUS], true))
            || ($daemonCores === null) !== ($masterCores === null)
            || ($daemonCores !== null && (!is_finite($daemonCores) || $daemonCores < 0 || round($daemonCores, 2) !== $daemonCores))
            || ($masterCores !== null && (!is_finite($masterCores) || $masterCores < 0
                || round($masterCores, 2) !== $masterCores || $masterCores > $daemonCores))
            || ($machineLoad !== null && ($machineLoad < 0 || $machineLoad > 100))
            || ($masterMemory !== null && ($masterMemory < 0 || $masterMemory % self::BYTES_PER_MIB !== 0))
            || ($workersMemory !== null && ($workersMemory < 0 || $workersMemory % self::BYTES_PER_MIB !== 0))
            || $workersMeasured < 0 || ($workersMemory === null && $workersMeasured !== 0)
            || ($memoryCeiling === null) !== ($memoryCeilingBound === null)
            || ($memoryCeiling !== null && $memoryCeiling <= 0)
            || ($memoryCeilingBound !== null && !in_array($memoryCeilingBound, [self::CEILING_CGROUP, self::CEILING_MACHINE], true))
            || ($phpMemoryLimit !== null && $phpMemoryLimit <= 0)) {
            throw new InvalidFormatException('Daemon measurement values are inconsistent.');
        }
    }
}
