<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\LogAggregatorAgent;

/**
 * HilosLogsOverviewSignalData - Payload for Hilos logs overview page subscription (server → client).
 *
 * The figures are the CLUSTER's, merged by {@see LogAggregatorAgent} out of what every node
 * reported and read here out of {@see ClusterLogIndexMirror} (HIL-756); this page walks no
 * directory of its own and so never shows one node's logs as though they were all of them.
 *
 * {@see $available} has three answers and they are three different screens. Null: no merged picture
 * has arrived yet, because the aggregator is not placed, is moving between nodes, or has simply not
 * answered yet - the figures are unknown, not zero. False: the picture arrived and not one node
 * could read its log store, which is a fault to show. True: there are figures, and the rest of the
 * fields carry them.
 *
 * When readable, totalRotationsAllTime is a non-negative count; lastRotationAt is null if there
 * were no rotation folders yet. Key metrics (logKeys*, totalWeight*) are null unless available is
 * true.
 */
final class HilosLogsOverviewSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param ?bool $available Whether the cluster's log stores could be read, null while no merged picture has arrived
     * @param ?int $totalRotationsAllTime Number of rotation timestamp folders (null if unavailable)
     * @param ?string $lastRotationAt ISO 8601 datetime of the latest rotation (null if none or unavailable)
     * @param ?int $logKeysPerAgent Distinct agent-*.log basenames across archive and live (null if unavailable)
     * @param ?int $totalWeightAgentKeysBytes Sum of agent log file sizes across all batches and live (null if unavailable)
     * @param ?int $logKeysPerWorker Distinct worker + worker-monopolistic basenames (null if unavailable)
     * @param ?int $totalWeightWorkerKeysBytes Sum of worker log file sizes (null if unavailable)
     */
    public function __construct(
        public readonly ?bool $available,
        public readonly ?int $totalRotationsAllTime,
        public readonly ?string $lastRotationAt,
        public readonly ?int $logKeysPerAgent,
        public readonly ?int $totalWeightAgentKeysBytes,
        public readonly ?int $logKeysPerWorker,
        public readonly ?int $totalWeightWorkerKeysBytes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'totalRotationsAllTime' => $this->totalRotationsAllTime,
            'lastRotationAt' => $this->lastRotationAt,
            'logKeysPerAgent' => $this->logKeysPerAgent,
            'totalWeightAgentKeysBytes' => $this->totalWeightAgentKeysBytes,
            'logKeysPerWorker' => $this->logKeysPerWorker,
            'totalWeightWorkerKeysBytes' => $this->totalWeightWorkerKeysBytes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $available = $data['available'] ?? null;
        $total = $data['totalRotationsAllTime'] ?? null;
        $last = $data['lastRotationAt'] ?? null;

        return new static(
            // Anything that is not a bool reads as null - "we do not know" - rather than as false:
            // false is the claim that the stores were read and none of them answered.
            available: is_bool($available) ? $available : null,
            totalRotationsAllTime: is_int($total) ? $total : (is_numeric($total) ? (int) $total : null),
            lastRotationAt: is_string($last) ? $last : null,
            logKeysPerAgent: self::optionalNonNegativeInt($data['logKeysPerAgent'] ?? null),
            totalWeightAgentKeysBytes: self::optionalNonNegativeInt($data['totalWeightAgentKeysBytes'] ?? null),
            logKeysPerWorker: self::optionalNonNegativeInt($data['logKeysPerWorker'] ?? null),
            totalWeightWorkerKeysBytes: self::optionalNonNegativeInt($data['totalWeightWorkerKeysBytes'] ?? null),
        );
    }

    /**
     * @param mixed $value
     */
    private static function optionalNonNegativeInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return max(0, (int) $value);
        }
        if (is_float($value) && is_finite($value)) {
            return max(0, (int) $value);
        }

        return null;
    }
}
