<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\LogWorkerSummary;
use Hilos\Log\NodeLogIndex;

/**
 * {@see LogStoreAgent} on one node → the cluster-wide {@see LogAggregatorAgent}: this is my whole
 * store (HIL-755).
 *
 * The wire form of a {@see NodeLogIndex}, and the only thing that crosses between the node that
 * owns a log directory and the one agent that holds the cluster's picture of them all. It carries
 * the index WHOLE and never a difference: a lost frame, a restarted aggregator and an aggregator
 * moved to another node are then all repaired by the next ordinary frame, with no protocol for
 * asking anybody to send everything again.
 *
 * The three kinds of row it carries - batches, keys and worker streams - are laid out by the
 * private helpers below rather than by `toArray()` methods on
 * {@see LogBatchSummary}, {@see LogKeySummary} and {@see LogWorkerSummary}: those are the log's
 * internal read value-objects, and a wire shape hung on them would make every reader of the index
 * a reader of this signal's contract.
 *
 * Every payload key is named after the field it carries, so the wire reads the same as the objects
 * on both ends. {@see self::streamClass} is the one exception, and not by choice: `class` cannot
 * be a class-constant name in PHP.
 *
 * Two of the keys are not measurements at all. A batch carries {@see self::takenAt}, the operator's
 * own confirmation that it has been carried off, and the index carries {@see self::logDirectory},
 * the absolute root the node measured (HIL-483). Both exist because nobody else can supply them:
 * the confirmation lives in a marker file on that machine, and a page worker holding the cluster
 * picture knows its own log root and no other node's.
 */
final class NodeLogIndexSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: cluster node this index was measured on, absent in a single-node installation. */
    public const string nodeId = 'nodeId';

    /** Payload key: whether the log store could be read at all. */
    public const string available = 'available';

    /** Payload key: Unix timestamp of the walk this index was built from. */
    public const string sampledAt = 'sampledAt';

    /** Payload key: rotation batches, ascending by timestamp. */
    public const string batches = 'batches';

    /** Payload key: log keys across live and archive, ascending by key. */
    public const string keys = 'keys';

    /** Payload key: worker streams, ascending by key. */
    public const string workers = 'workers';

    /** Payload key: key → bytes written over the last day, null for a window that has not filled. */
    public const string growthBytesPerDay = 'growthBytesPerDay';

    /** Payload key: absolute log root of the reporting node, absent when its environment names none. */
    public const string logDirectory = 'logDirectory';

    /** Batch row key: Unix timestamp of the rotation folder. */
    public const string timestamp = 'timestamp';

    /** Batch row key: number of `agent-*.log` files in the batch. */
    public const string agentFileCount = 'agentFileCount';

    /** Batch row key: summed size in bytes of the agent files. */
    public const string agentBytes = 'agentBytes';

    /** Batch row key: number of `worker-*.log` files in the batch, monopolistic ones apart. */
    public const string workerFileCount = 'workerFileCount';

    /** Batch row key: summed size in bytes of the worker files. */
    public const string workerBytes = 'workerBytes';

    /** Batch row key: number of `worker-monopolistic-*.log` files in the batch. */
    public const string workerMonopolisticFileCount = 'workerMonopolisticFileCount';

    /** Batch row key: summed size in bytes of the worker-monopolistic files. */
    public const string workerMonopolisticBytes = 'workerMonopolisticBytes';

    /** Batch row key: number of daemon files in the batch. */
    public const string daemonFileCount = 'daemonFileCount';

    /** Batch row key: summed size in bytes of the daemon files. */
    public const string daemonBytes = 'daemonBytes';

    /** Batch row key: instant an operator confirmed carrying the batch off, absent while none has. */
    public const string takenAt = 'takenAt';

    /** Key and worker row key: file basename, stable across batches. */
    public const string key = 'key';

    /**
     * Key row key: stream class, one of the {@see LogKeySummary} class values.
     *
     * Named `streamClass` where every other constant here is named after its field, because
     * `class` is reserved as a class-constant name in PHP. The wire key is still `class`.
     */
    public const string streamClass = 'class';

    /** Worker row key: whether the stream is a monopolistic worker. */
    public const string monopolistic = 'monopolistic';

    /** Key and worker row key: whether the key is present among the live files. */
    public const string live = 'live';

    /** Key and worker row key: ascending Unix timestamps of the batches the key occurs in. */
    public const string batchTimestamps = 'batchTimestamps';

    /** Key and worker row key: summed size in bytes across the live file and every batch. */
    public const string totalBytes = 'totalBytes';

    /**
     * @param ?string $nodeId Cluster node this index was measured on, or null in a single-node installation
     * @param bool $available Whether the log store could be read
     * @param int $sampledAt Unix timestamp of the walk this index was built from
     * @param list<LogBatchSummary> $batches Rotation batches, ascending by timestamp
     * @param list<LogKeySummary> $keys Log keys across live and archive, ascending by key
     * @param list<LogWorkerSummary> $workers Worker streams, ascending by key
     * @param array<string, ?int> $growthBytesPerDay Key → bytes written over the last day, null until the window fills
     * @param ?string $logDirectory Absolute log root of the reporting node, or null when its environment names none
     */
    public function __construct(
        public readonly ?string $nodeId,
        public readonly bool $available,
        public readonly int $sampledAt,
        public readonly array $batches,
        public readonly array $keys,
        public readonly array $workers,
        public readonly array $growthBytesPerDay,
        public readonly ?string $logDirectory = null,
    ) {
    }

    /**
     * Wraps the index a node just measured for the trip to the aggregator.
     *
     * @param NodeLogIndex $index Index as the owner of the directory holds it
     * @return self Payload carrying that index whole
     */
    public static function fromIndex(NodeLogIndex $index): self
    {
        return new self(
            nodeId: $index->nodeId,
            available: $index->available,
            sampledAt: $index->sampledAt,
            batches: $index->batches,
            keys: $index->keys,
            workers: $index->workers,
            growthBytesPerDay: $index->growthBytesPerDay,
            logDirectory: $index->logDirectory,
        );
    }

    /**
     * @return array<string, mixed> Index as it goes out to the aggregator
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::available => $this->available,
            self::sampledAt => $this->sampledAt,
            self::batches => array_map(
                static fn (LogBatchSummary $batch): array => self::batchToArray($batch),
                $this->batches,
            ),
            self::keys => array_map(
                static fn (LogKeySummary $key): array => self::keyToArray($key),
                $this->keys,
            ),
            self::workers => array_map(
                static fn (LogWorkerSummary $worker): array => self::workerToArray($worker),
                $this->workers,
            ),
            self::growthBytesPerDay => $this->growthBytesPerDay,
            self::logDirectory => $this->logDirectory,
        ];
    }

    /**
     * Reads a frame back from its wire form.
     *
     * A row that is not an object, and a row or a field the index has no meaning without, are
     * refused rather than filled in: an index that repaired itself here would put figures in the
     * cluster picture that no node ever measured. {@see self::nodeId} is the one field allowed to
     * be absent, because a single-node installation has no id to name itself with.
     *
     * @param array<string, mixed> $data Wire form of one node's index
     * @return static Restored payload
     * @throws InvalidFormatException When a row is not an object, or a field the index has no
     *     meaning without is absent or holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        $batches = [];
        foreach (self::rows($data, self::batches) as $row) {
            $batches[] = self::batchFromArray($row);
        }

        $keys = [];
        foreach (self::rows($data, self::keys) as $row) {
            $keys[] = self::keyFromArray($row);
        }

        $workers = [];
        foreach (self::rows($data, self::workers) as $row) {
            $workers[] = self::workerFromArray($row);
        }

        return new static(
            nodeId: self::optionalString($data, self::nodeId),
            available: self::requireBool($data, self::available),
            sampledAt: self::requireInt($data, self::sampledAt),
            batches: $batches,
            keys: $keys,
            workers: $workers,
            growthBytesPerDay: self::growthFromArray($data),
            logDirectory: self::optionalString($data, self::logDirectory),
        );
    }

    /**
     * Unwraps the payload back into the index the aggregator files under the node's slot.
     *
     * @return NodeLogIndex Index as the node measured it
     */
    public function toIndex(): NodeLogIndex
    {
        return new NodeLogIndex(
            nodeId: $this->nodeId,
            available: $this->available,
            sampledAt: $this->sampledAt,
            batches: $this->batches,
            keys: $this->keys,
            workers: $this->workers,
            growthBytesPerDay: $this->growthBytesPerDay,
            logDirectory: $this->logDirectory,
        );
    }

    /**
     * Reads one list of rows, refusing anything in it that is not an object.
     *
     * @param array<string, mixed> $data Wire form of one node's index
     * @param string $listKey Payload key holding the list
     * @return list<array<string, mixed>> Rows of that list
     * @throws InvalidFormatException When the list is absent or carries a row that is not an object
     */
    private static function rows(array $data, string $listKey): array
    {
        $rows = [];
        foreach (self::requireArray($data, $listKey) as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Node log index carries a row that is not an object under key ' . $listKey);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param LogBatchSummary $batch Batch to lay out
     * @return array<string, mixed> Batch row
     */
    private static function batchToArray(LogBatchSummary $batch): array
    {
        return [
            self::timestamp => $batch->timestamp,
            self::agentFileCount => $batch->agentFileCount,
            self::agentBytes => $batch->agentBytes,
            self::workerFileCount => $batch->workerFileCount,
            self::workerBytes => $batch->workerBytes,
            self::workerMonopolisticFileCount => $batch->workerMonopolisticFileCount,
            self::workerMonopolisticBytes => $batch->workerMonopolisticBytes,
            self::daemonFileCount => $batch->daemonFileCount,
            self::daemonBytes => $batch->daemonBytes,
            self::takenAt => $batch->takenAt,
        ];
    }

    /**
     * @param array<string, mixed> $row Batch row from the wire
     * @return LogBatchSummary Batch the row describes
     * @throws InvalidFormatException When a field is absent or holds a value of the wrong type
     */
    private static function batchFromArray(array $row): LogBatchSummary
    {
        return new LogBatchSummary(
            timestamp: self::requireInt($row, self::timestamp),
            agentFileCount: self::requireInt($row, self::agentFileCount),
            agentBytes: self::requireInt($row, self::agentBytes),
            workerFileCount: self::requireInt($row, self::workerFileCount),
            workerBytes: self::requireInt($row, self::workerBytes),
            workerMonopolisticFileCount: self::requireInt($row, self::workerMonopolisticFileCount),
            workerMonopolisticBytes: self::requireInt($row, self::workerMonopolisticBytes),
            daemonFileCount: self::requireInt($row, self::daemonFileCount),
            daemonBytes: self::requireInt($row, self::daemonBytes),
            takenAt: self::optionalInt($row, self::takenAt),
        );
    }

    /**
     * @param LogKeySummary $key Key to lay out
     * @return array<string, mixed> Key row
     */
    private static function keyToArray(LogKeySummary $key): array
    {
        return [
            self::key => $key->key,
            self::streamClass => $key->class,
            self::live => $key->live,
            self::batchTimestamps => $key->batchTimestamps,
            self::totalBytes => $key->totalBytes,
        ];
    }

    /**
     * @param array<string, mixed> $row Key row from the wire
     * @return LogKeySummary Key the row describes
     * @throws InvalidFormatException When a field is absent or holds a value of the wrong type
     */
    private static function keyFromArray(array $row): LogKeySummary
    {
        return new LogKeySummary(
            key: self::requireString($row, self::key),
            class: self::requireString($row, self::streamClass),
            live: self::requireBool($row, self::live),
            batchTimestamps: self::timestampsFromArray($row),
            totalBytes: self::requireInt($row, self::totalBytes),
        );
    }

    /**
     * @param LogWorkerSummary $worker Worker stream to lay out
     * @return array<string, mixed> Worker row
     */
    private static function workerToArray(LogWorkerSummary $worker): array
    {
        return [
            self::key => $worker->key,
            self::monopolistic => $worker->monopolistic,
            self::live => $worker->live,
            self::batchTimestamps => $worker->batchTimestamps,
            self::totalBytes => $worker->totalBytes,
        ];
    }

    /**
     * @param array<string, mixed> $row Worker row from the wire
     * @return LogWorkerSummary Worker stream the row describes
     * @throws InvalidFormatException When a field is absent or holds a value of the wrong type
     */
    private static function workerFromArray(array $row): LogWorkerSummary
    {
        return new LogWorkerSummary(
            key: self::requireString($row, self::key),
            monopolistic: self::requireBool($row, self::monopolistic),
            live: self::requireBool($row, self::live),
            batchTimestamps: self::timestampsFromArray($row),
            totalBytes: self::requireInt($row, self::totalBytes),
        );
    }

    /**
     * Reads the batch timestamps a key or a worker row carries.
     *
     * @param array<string, mixed> $row Key or worker row from the wire
     * @return list<int> Batch timestamps, in the order the row lists them
     * @throws InvalidFormatException When the list is absent or carries something that is not a timestamp
     */
    private static function timestampsFromArray(array $row): array
    {
        $timestamps = [];
        foreach (self::requireArray($row, self::batchTimestamps) as $timestamp) {
            if (!is_int($timestamp)) {
                throw new InvalidFormatException('Node log index carries a batch timestamp that is not an integer');
            }

            $timestamps[] = $timestamp;
        }

        return $timestamps;
    }

    /**
     * Reads the day-growth map, keeping the null a window that has not filled yet reports.
     *
     * The null is the whole point of reading this by hand: it means "we do not know yet", where a
     * zero would claim the key was not written to at all.
     *
     * @param array<string, mixed> $data Wire form of one node's index
     * @return array<string, ?int> Key → bytes written over the last day, or null
     * @throws InvalidFormatException When the map is absent or carries a value that is neither a
     *     byte count nor null
     */
    private static function growthFromArray(array $data): array
    {
        $growth = [];
        foreach (self::requireArray($data, self::growthBytesPerDay) as $key => $bytes) {
            if ($bytes !== null && !is_int($bytes)) {
                throw new InvalidFormatException('Node log index carries a day growth that is neither an integer nor null');
            }

            $growth[(string)$key] = $bytes;
        }

        return $growth;
    }
}
