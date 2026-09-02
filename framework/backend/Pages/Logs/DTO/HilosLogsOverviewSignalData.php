<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
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
 *
 * {@see $nodes} carries the per-node table, and only nodes that have a name of their own: a
 * single-node installation reports under no name, and the empty list is how the screen is told
 * there is no node to speak of rather than offered a table of one row.
 */
final class HilosLogsOverviewSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: whether the cluster's log stores could be read, null while no picture has arrived. */
    public const string available = 'available';

    /** Payload key: rotation batch folders summed over the cluster. */
    public const string totalRotationsAllTime = 'totalRotationsAllTime';

    /** Payload key: newest rotation anywhere in the cluster, ISO 8601. */
    public const string lastRotationAt = 'lastRotationAt';

    /** Payload key: distinct agent stream names across archive and live. */
    public const string logKeysPerAgent = 'logKeysPerAgent';

    /** Payload key: what the agent streams weigh. */
    public const string totalWeightAgentKeysBytes = 'totalWeightAgentKeysBytes';

    /** Payload key: distinct worker stream names, monopolistic ones included. */
    public const string logKeysPerWorker = 'logKeysPerWorker';

    /** Payload key: what the worker streams weigh. */
    public const string totalWeightWorkerKeysBytes = 'totalWeightWorkerKeysBytes';

    /** Payload key: bytes written over the last day, null while no stream's window has filled. */
    public const string growthBytesPerDay = 'growthBytesPerDay';

    /** Payload key: streams whose day window is not a day old yet. */
    public const string keysWithoutGrowthWindow = 'keysWithoutGrowthWindow';

    /** Payload key: rotation batches past their retention, cluster-wide. */
    public const string batchesDueForTakeout = 'batchesDueForTakeout';

    /** Payload key: the named nodes of the picture, one row each; empty in a single-node installation. */
    public const string nodes = 'nodes';

    /** Node row key: cluster node id, always a name - a node without one does not travel here. */
    public const string nodeId = 'nodeId';

    /** Node row key: what this node's own archive weighs. */
    public const string archiveBytes = 'archiveBytes';

    /** Node row key: what this node's live files weigh, the archive taken back out. */
    public const string liveBytes = 'liveBytes';

    /**
     * @param ?bool $available Whether the cluster's log stores could be read, null while no merged picture has arrived
     * @param ?int $totalRotationsAllTime Number of rotation timestamp folders (null if unavailable)
     * @param ?string $lastRotationAt ISO 8601 datetime of the latest rotation (null if none or unavailable)
     * @param ?int $logKeysPerAgent Distinct agent-*.log basenames across archive and live (null if unavailable)
     * @param ?int $totalWeightAgentKeysBytes Sum of agent log file sizes across all batches and live (null if unavailable)
     * @param ?int $logKeysPerWorker Distinct worker + worker-monopolistic basenames (null if unavailable)
     * @param ?int $totalWeightWorkerKeysBytes Sum of worker log file sizes (null if unavailable)
     * @param ?int $growthBytesPerDay Bytes written cluster-wide over the last day, null while no window has filled
     * @param ?int $keysWithoutGrowthWindow Streams whose day window has not filled yet (null if unavailable)
     * @param ?int $batchesDueForTakeout Rotation batches past their retention across the cluster (null if unavailable)
     * @param list<array{nodeId: string, available: bool, lastRotationAt: ?string, liveBytes: ?int,
     *     archiveBytes: ?int, growthBytesPerDay: ?int, batchesDueForTakeout: ?int}> $nodes
     *     Named nodes of the picture, one row each; empty in a single-node installation
     */
    public function __construct(
        public readonly ?bool $available,
        public readonly ?int $totalRotationsAllTime,
        public readonly ?string $lastRotationAt,
        public readonly ?int $logKeysPerAgent,
        public readonly ?int $totalWeightAgentKeysBytes,
        public readonly ?int $logKeysPerWorker,
        public readonly ?int $totalWeightWorkerKeysBytes,
        public readonly ?int $growthBytesPerDay,
        public readonly ?int $keysWithoutGrowthWindow,
        public readonly ?int $batchesDueForTakeout,
        public readonly array $nodes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::available => $this->available,
            self::totalRotationsAllTime => $this->totalRotationsAllTime,
            self::lastRotationAt => $this->lastRotationAt,
            self::logKeysPerAgent => $this->logKeysPerAgent,
            self::totalWeightAgentKeysBytes => $this->totalWeightAgentKeysBytes,
            self::logKeysPerWorker => $this->logKeysPerWorker,
            self::totalWeightWorkerKeysBytes => $this->totalWeightWorkerKeysBytes,
            self::growthBytesPerDay => $this->growthBytesPerDay,
            self::keysWithoutGrowthWindow => $this->keysWithoutGrowthWindow,
            self::batchesDueForTakeout => $this->batchesDueForTakeout,
            self::nodes => $this->nodes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidFormatException When the node list is absent, or a row in it is not an object,
     *     or a row omits the name or the readability the row has no meaning without
     */
    public static function fromArray(array $data): static
    {
        $available = $data[self::available] ?? null;
        $total = $data[self::totalRotationsAllTime] ?? null;
        $last = $data[self::lastRotationAt] ?? null;

        return new static(
            // Anything that is not a bool reads as null - "we do not know" - rather than as false:
            // false is the claim that the stores were read and none of them answered.
            available: is_bool($available) ? $available : null,
            totalRotationsAllTime: is_int($total) ? $total : (is_numeric($total) ? (int) $total : null),
            lastRotationAt: is_string($last) ? $last : null,
            logKeysPerAgent: self::optionalNonNegativeInt($data[self::logKeysPerAgent] ?? null),
            totalWeightAgentKeysBytes: self::optionalNonNegativeInt($data[self::totalWeightAgentKeysBytes] ?? null),
            logKeysPerWorker: self::optionalNonNegativeInt($data[self::logKeysPerWorker] ?? null),
            totalWeightWorkerKeysBytes: self::optionalNonNegativeInt($data[self::totalWeightWorkerKeysBytes] ?? null),
            growthBytesPerDay: self::optionalNonNegativeInt($data[self::growthBytesPerDay] ?? null),
            keysWithoutGrowthWindow: self::optionalNonNegativeInt($data[self::keysWithoutGrowthWindow] ?? null),
            batchesDueForTakeout: self::optionalNonNegativeInt($data[self::batchesDueForTakeout] ?? null),
            nodes: self::nodeRows($data),
        );
    }

    /**
     * Reads the per-node table back, refusing a row that is not one.
     *
     * The name and the readability are required, because a row without either is not a node the
     * table could draw: an unnamed row belongs to a single-node installation, which sends no rows
     * at all. The figures are optional in the same sense the top-level ones are - a node that
     * could not be read carries null in every one of them, and reading an absent number as zero
     * would report a measurement nobody took.
     *
     * @param array<string, mixed> $data Wire form of the overview
     * @return list<array{nodeId: string, available: bool, lastRotationAt: ?string, liveBytes: ?int,
     *     archiveBytes: ?int, growthBytesPerDay: ?int, batchesDueForTakeout: ?int}> Rows of the table
     * @throws InvalidFormatException When the list is absent, holds a row that is not an object,
     *     or a row omits its name or its readability
     */
    private static function nodeRows(array $data): array
    {
        $rows = [];
        foreach (self::requireArray($data, self::nodes) as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Logs overview carries a node row that is not an object');
            }

            $lastRotationAt = $row[self::lastRotationAt] ?? null;
            $rows[] = [
                self::nodeId => self::requireString($row, self::nodeId),
                self::available => self::requireBool($row, self::available),
                self::lastRotationAt => is_string($lastRotationAt) ? $lastRotationAt : null,
                self::liveBytes => self::optionalNonNegativeInt($row[self::liveBytes] ?? null),
                self::archiveBytes => self::optionalNonNegativeInt($row[self::archiveBytes] ?? null),
                self::growthBytesPerDay => self::optionalNonNegativeInt($row[self::growthBytesPerDay] ?? null),
                self::batchesDueForTakeout => self::optionalNonNegativeInt($row[self::batchesDueForTakeout] ?? null),
            ];
        }

        return $rows;
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
