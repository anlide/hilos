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

    /** Payload key: the cluster's last failures inside the panel's window, newest first. */
    public const string recentErrors = 'recentErrors';

    /** Payload key: whether that list was cut at the limit, which the screen reads as "10+". */
    public const string recentErrorsCapped = 'recentErrorsCapped';

    /** Payload key: the cluster's last warnings inside the panel's window, newest first (HIL-868). */
    public const string recentWarnings = 'recentWarnings';

    /** Payload key: whether the warnings list was cut at the limit, which its tab reads as "10+". */
    public const string recentWarningsCapped = 'recentWarningsCapped';

    /**
     * Payload key: free bytes on the filesystem holding the log root (HIL-869).
     *
     * Declared once and read in both halves of the frame, the way {@see self::growthBytesPerDay}
     * already is: a single-node installation has no node row to put it in and carries it in the
     * header, a cluster carries one per node row and leaves the header null. Free space is never
     * summed across the cluster — each node has a filesystem of its own, and the sum of free bytes
     * answers no question anybody asks.
     */
    public const string filesystemFreeBytes = 'filesystemFreeBytes';

    /** Payload key: whole size in bytes of that same filesystem, null when not known. */
    public const string filesystemTotalBytes = 'filesystemTotalBytes';

    /** Payload key: share of the volume the node keeps free, in percent, as that node resolved it. */
    public const string freeSpaceThresholdPercent = 'freeSpaceThresholdPercent';

    /** Node row key: cluster node id, always a name - a node without one does not travel here. */
    public const string nodeId = 'nodeId';

    /** Node row key: what this node's own archive weighs. */
    public const string archiveBytes = 'archiveBytes';

    /** Node row key: what this node's live files weigh, the archive taken back out. */
    public const string liveBytes = 'liveBytes';

    /** Error row key: basename of the live stream the line was written to. */
    public const string stream = 'stream';

    /** Error row key: instant the line was written, ISO 8601 with milliseconds. */
    public const string at = 'at';

    /** Error row key: line text, already cut by the node that read it. */
    public const string message = 'message';

    /** Error row key: frames in the entry's stack trace, null when the entry carries none. */
    public const string traceFrames = 'traceFrames';

    /**
     * Value of {@see self::nodeId} in a row of either recent feed from an installation whose nodes have no names.
     *
     * A value and not an absence, which is why it is spelled out here rather than left to a
     * fallback: the viewer address reads the empty id as "the node you are on" and draws it as its
     * own segment, where a missing id would mean no file was named at all. The screen is told
     * which file to open either way — only the way of naming the machine differs.
     */
    public const string SELF_NODE_ID = '';

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
     *     archiveBytes: ?int, growthBytesPerDay: ?int, batchesDueForTakeout: ?int,
     *     filesystemFreeBytes: ?int, filesystemTotalBytes: ?int, freeSpaceThresholdPercent: ?int}> $nodes
     *     Named nodes of the picture, one row each; empty in a single-node installation
     * @param list<array{nodeId: string, stream: string, at: string, message: string, traceFrames: ?int}> $recentErrors
     *     Last failures across the cluster inside the panel's window, newest first; the node id is
     *     an empty string in a single-node installation, which is what the viewer address expects
     * @param bool $recentErrorsCapped Whether that list was cut at the limit, so the screen says "10+"
     * @param list<array{nodeId: string, stream: string, at: string, message: string, traceFrames: ?int}> $recentWarnings
     *     Last warnings across the cluster inside the panel's window, newest first, in the same row as the failures
     * @param bool $recentWarningsCapped Whether the warnings list was cut at the limit, so its tab says "10+"
     * @param ?int $filesystemFreeBytes Free bytes on this installation's log filesystem, null in a cluster or when not known
     * @param ?int $filesystemTotalBytes Whole size of that filesystem, null in a cluster or when not known
     * @param ?int $freeSpaceThresholdPercent Share of the volume kept free, null in a cluster or when not known
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
        public readonly array $recentErrors = [],
        public readonly bool $recentErrorsCapped = false,
        public readonly array $recentWarnings = [],
        public readonly bool $recentWarningsCapped = false,
        public readonly ?int $filesystemFreeBytes = null,
        public readonly ?int $filesystemTotalBytes = null,
        public readonly ?int $freeSpaceThresholdPercent = null,
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
            self::recentErrors => $this->recentErrors,
            self::recentErrorsCapped => $this->recentErrorsCapped,
            self::recentWarnings => $this->recentWarnings,
            self::recentWarningsCapped => $this->recentWarningsCapped,
            self::filesystemFreeBytes => $this->filesystemFreeBytes,
            self::filesystemTotalBytes => $this->filesystemTotalBytes,
            self::freeSpaceThresholdPercent => $this->freeSpaceThresholdPercent,
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
            recentErrors: self::recentRows($data, self::recentErrors),
            recentErrorsCapped: ($data[self::recentErrorsCapped] ?? null) === true,
            recentWarnings: self::recentRows($data, self::recentWarnings),
            recentWarningsCapped: ($data[self::recentWarningsCapped] ?? null) === true,
            filesystemFreeBytes: self::optionalNonNegativeInt($data[self::filesystemFreeBytes] ?? null),
            filesystemTotalBytes: self::optionalNonNegativeInt($data[self::filesystemTotalBytes] ?? null),
            freeSpaceThresholdPercent: self::optionalNonNegativeInt($data[self::freeSpaceThresholdPercent] ?? null),
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
     *     archiveBytes: ?int, growthBytesPerDay: ?int, batchesDueForTakeout: ?int,
     *     filesystemFreeBytes: ?int, filesystemTotalBytes: ?int, freeSpaceThresholdPercent: ?int}> Rows of the table
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
                self::filesystemFreeBytes => self::optionalNonNegativeInt($row[self::filesystemFreeBytes] ?? null),
                self::filesystemTotalBytes => self::optionalNonNegativeInt($row[self::filesystemTotalBytes] ?? null),
                self::freeSpaceThresholdPercent => self::optionalNonNegativeInt(
                    $row[self::freeSpaceThresholdPercent] ?? null,
                ),
            ];
        }

        return $rows;
    }

    /**
     * Reads one feed of the panel back — errors or warnings — refusing a row that is not one (HIL-867, HIL-868).
     *
     * Every field of a row is required but the frame count, and the node id is required as a
     * STRING that may be empty: a single-node installation has no name to give, and the address
     * the row leads to wants the empty string in that place rather than nothing at all. The frame
     * count keeps its null, which is the difference between "there is a stack to look at" and
     * "there is not" — read as zero it would put a badge on every row.
     *
     * Both feeds share this reader because they share the row: a second reading of it would be a
     * second answer to what a row of the panel is.
     *
     * @param array<string, mixed> $data Wire form of the overview
     * @param string $listKey Payload key holding the feed, {@see self::recentErrors} or {@see self::recentWarnings}
     * @return list<array{nodeId: string, stream: string, at: string, message: string, traceFrames: ?int}> Rows of that tab
     * @throws InvalidFormatException When the list is absent, holds a row that is not an object, or
     *     a row omits a field it has no meaning without
     */
    private static function recentRows(array $data, string $listKey): array
    {
        $rows = [];
        foreach (self::requireArray($data, $listKey) as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Logs overview carries a recent entry that is not an object under key ' . $listKey);
            }

            $rows[] = [
                self::nodeId => self::requireString($row, self::nodeId),
                self::stream => self::requireString($row, self::stream),
                self::at => self::requireString($row, self::at),
                self::message => self::requireString($row, self::message),
                self::traceFrames => self::optionalNonNegativeInt($row[self::traceFrames] ?? null),
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
