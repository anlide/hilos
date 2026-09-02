<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\LogBatchSummary;

/**
 * Backend row payload for the framework log-rotations table (HIL-387).
 *
 * One row is one archived rotation batch ON ONE NODE, projected from the
 * {@see LogBatchSummary} that node reported into {@see ClusterLogIndexMirror}. The same
 * rotation moment on two nodes is two rows and not one: the archives are two directories on
 * two machines, evicted apart, and folding them would show an operator a batch that exists
 * nowhere.
 *
 * The batch identity rides the row fragment's {@see rowKey}, never a field named `id` inside
 * the slot: a slot payload carrying `id` is ingested by the frontend normalizer as an entity
 * fragment and replaced with a reference, which would strip every other field off this row
 * ({@see AbstractTableRow}).
 *
 * The three file counts are the classes an operator can act on — agent, worker, and the
 * monopolistic workers apart from the rest. The daemon's own streams are a fourth class and
 * are deliberately absent from the counts while being part of {@see $bytes}: the weight is
 * what the directory costs on disk, and the daemon files cost it too.
 */
final class HilosLogRotationsTableRow extends AbstractTableRow
{
    /**
     * Payload key of the row identity (`<node>:<timestamp>`, the node's own dash when it has no id).
     *
     * It rides the row fragment's `rowKey`, never a field inside the slot, so the frontend
     * normalizer keeps the row whole ({@see HilosLogRotationsTableRow}).
     */
    public const string rowKey = 'rowKey';

    public const string batchAt = 'batchAt';
    public const string node = 'node';
    public const string path = 'path';
    public const string agentFileCount = 'agentFileCount';
    public const string workerFileCount = 'workerFileCount';
    public const string workerMonopolisticFileCount = 'workerMonopolisticFileCount';
    public const string bytes = 'bytes';
    public const string retentionState = 'retentionState';

    /**
     * @param string $rowKey Stable row key, `<node>:<timestamp>`
     * @param int $batchAt Unix timestamp of the rotation batch
     * @param ?string $node Cluster node holding the batch, null in a single-node installation
     * @param string $path Archive directory of the batch, relative to the node's log root
     * @param int $agentFileCount Number of agent files in the batch
     * @param int $workerFileCount Number of worker files in the batch (monopolistic ones apart)
     * @param int $workerMonopolisticFileCount Number of monopolistic worker files in the batch
     * @param int $bytes Weight of the whole batch directory, every stream class included
     * @param string $retentionState One of the {@see HilosLogRotationsTable} retention states
     */
    public function __construct(
        public string $rowKey,
        public int $batchAt,
        public ?string $node,
        public string $path,
        public int $agentFileCount,
        public int $workerFileCount,
        public int $workerMonopolisticFileCount,
        public int $bytes,
        public string $retentionState,
    ) {
    }

    /**
     * Returns the stable table row key.
     *
     * @return string Row key
     */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /**
     * Serializes the row to the log-rotations table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            // The key rides the payload under a name the normalizer ignores; `id` would make
            // the whole slot look like an entity fragment on the frontend.
            self::rowKey => $this->rowKey,
            self::batchAt => $this->batchAt,
            self::node => $this->node,
            self::path => $this->path,
            self::agentFileCount => $this->agentFileCount,
            self::workerFileCount => $this->workerFileCount,
            self::workerMonopolisticFileCount => $this->workerMonopolisticFileCount,
            self::bytes => $this->bytes,
            self::retentionState => $this->retentionState,
        ];
    }

    /**
     * Builds a rotation row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed rotation table row
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            batchAt: self::requireInt($data, self::batchAt),
            node: self::optionalString($data, self::node),
            path: self::requireString($data, self::path),
            agentFileCount: self::requireInt($data, self::agentFileCount),
            workerFileCount: self::requireInt($data, self::workerFileCount),
            workerMonopolisticFileCount: self::requireInt($data, self::workerMonopolisticFileCount),
            bytes: self::requireInt($data, self::bytes),
            retentionState: self::requireString($data, self::retentionState),
        );
    }
}
