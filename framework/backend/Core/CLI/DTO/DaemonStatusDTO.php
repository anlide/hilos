<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Exception\InvalidFormatException;

/** Status of the daemon at a request, with the master's last completed measurement. */
class DaemonStatusDTO extends BaseDTO
{
    public const string UPTIME = 'uptime';
    public const string TIMESTAMP = 'timestamp';
    public const string MEASUREMENTS = 'measurements';
    public const string WORKERS_REGULAR = 'workersRegular';
    public const string WORKERS_MONOPOLISTIC = 'workersMonopolistic';
    public const string WORKERS_MAX_REGULAR = 'workersMaxRegular';

    public const string STARTED_AT = 'startedAt';
    public const string CORES = 'cores';
    public const string CORES_BOUND = 'coresBound';
    public const string DAEMON_CORES = 'daemonCores';
    public const string MASTER_CORES = 'masterCores';
    public const string MACHINE_LOAD = 'machineLoad';
    public const string MASTER_MEMORY = 'masterMemory';
    public const string WORKERS_MEMORY = 'workersMemory';
    public const string WORKERS_MEASURED = 'workersMeasured';
    public const string MEMORY_CEILING = 'memoryCeiling';
    public const string MEMORY_CEILING_BOUND = 'memoryCeilingBound';
    public const string PHP_MEMORY_LIMIT = 'phpMemoryLimit';

    /**
     * @param int $uptime Uptime in seconds
     * @param int $timestamp Current Unix timestamp
     * @param DaemonMeasurements $measurements Last completed master measurement
     * @param int $workersRegular Number of regular workers
     * @param int $workersMonopolistic Number of monopolistic workers
     * @param int $workersMaxRegular Maximum regular worker capacity
     */
    public function __construct(
        public readonly int $uptime,
        public readonly int $timestamp,
        public readonly DaemonMeasurements $measurements,
        public readonly int $workersRegular = 0,
        public readonly int $workersMonopolistic = 0,
        public readonly int $workersMaxRegular = 0,
    ) {
    }

    /** @return array<string, mixed> Status payload */
    public function toArray(): array
    {
        return [
            self::UPTIME => $this->uptime,
            self::TIMESTAMP => $this->timestamp,
            self::MEASUREMENTS => self::measurementsToArray($this->measurements),
            self::WORKERS_REGULAR => $this->workersRegular,
            self::WORKERS_MONOPOLISTIC => $this->workersMonopolistic,
            self::WORKERS_MAX_REGULAR => $this->workersMaxRegular,
        ];
    }

    /**
     * @param array<string, mixed> $data Status payload
     * @return static Parsed status
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            uptime: self::requireInt($data, self::UPTIME),
            timestamp: self::requireInt($data, self::TIMESTAMP),
            measurements: self::measurementsFromArray(self::requireArray($data, self::MEASUREMENTS)),
            workersRegular: self::requireInt($data, self::WORKERS_REGULAR),
            workersMonopolistic: self::requireInt($data, self::WORKERS_MONOPOLISTIC),
            workersMaxRegular: self::requireInt($data, self::WORKERS_MAX_REGULAR),
        );
    }

    /**
     * @param DaemonMeasurements $measurements Master's last completed measurement
     * @return array<string, int|float|string|null> Shared measurement wire shape
     */
    public static function measurementsToArray(DaemonMeasurements $measurements): array
    {
        return [
            self::STARTED_AT => $measurements->startedAt,
            self::CORES => $measurements->cores,
            self::CORES_BOUND => $measurements->coresBound,
            self::DAEMON_CORES => $measurements->daemonCores,
            self::MASTER_CORES => $measurements->masterCores,
            self::MACHINE_LOAD => $measurements->machineLoad,
            self::MASTER_MEMORY => $measurements->masterMemory,
            self::WORKERS_MEMORY => $measurements->workersMemory,
            self::WORKERS_MEASURED => $measurements->workersMeasured,
            self::MEMORY_CEILING => $measurements->memoryCeiling,
            self::MEMORY_CEILING_BOUND => $measurements->memoryCeilingBound,
            self::PHP_MEMORY_LIMIT => $measurements->phpMemoryLimit,
        ];
    }

    /**
     * @param array<string, mixed> $data Shared measurement wire shape
     * @return DaemonMeasurements Parsed measurement
     * @throws InvalidFormatException When a required field or value relationship is invalid
     */
    public static function measurementsFromArray(array $data): DaemonMeasurements
    {
        return new DaemonMeasurements(
            startedAt: self::requireInt($data, self::STARTED_AT),
            cores: self::requiredNullableFloat($data, self::CORES),
            coresBound: self::requiredNullableString($data, self::CORES_BOUND),
            daemonCores: self::requiredNullableFloat($data, self::DAEMON_CORES),
            masterCores: self::requiredNullableFloat($data, self::MASTER_CORES),
            machineLoad: self::requiredNullableInt($data, self::MACHINE_LOAD),
            masterMemory: self::requiredNullableInt($data, self::MASTER_MEMORY),
            workersMemory: self::requiredNullableInt($data, self::WORKERS_MEMORY),
            workersMeasured: self::requireInt($data, self::WORKERS_MEASURED),
            memoryCeiling: self::requiredNullableInt($data, self::MEMORY_CEILING),
            memoryCeilingBound: self::requiredNullableString($data, self::MEMORY_CEILING_BOUND),
            phpMemoryLimit: self::requiredNullableInt($data, self::PHP_MEMORY_LIMIT),
        );
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable integer key
     * @return ?int Integer or explicit null
     * @throws InvalidFormatException When the field is absent or invalid
     */
    private static function requiredNullableInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . $key);
        }
        return self::optionalInt($data, $key);
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable number key
     * @return ?float Number or explicit null
     * @throws InvalidFormatException When the field is absent or invalid
     */
    private static function requiredNullableFloat(array $data, string $key): ?float
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no number or null under key ' . $key);
        }
        return self::optionalFloat($data, $key);
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable string key
     * @return ?string String or explicit null
     * @throws InvalidFormatException When the field is absent or invalid
     */
    private static function requiredNullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no string or null under key ' . $key);
        }
        return self::optionalString($data, $key);
    }
}
