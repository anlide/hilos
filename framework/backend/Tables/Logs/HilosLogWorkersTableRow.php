<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\LogWorkerSummary;

/**
 * Backend row payload for the framework log-workers table (HIL-386).
 *
 * One row is one worker stream ON ONE NODE, projected from the {@see LogWorkerSummary} that node
 * reported into {@see ClusterLogIndexMirror}. The same `worker-0.log` on two nodes is two files on
 * two machines, and folding them by name would understate both the weight and the count.
 *
 * The key identity rides the row fragment's {@see rowKey}, never a field named `id` inside the
 * slot: a slot payload carrying `id` is ingested by the frontend normalizer as an entity fragment
 * and replaced with a reference, which would strip every other field off this row
 * ({@see AbstractTableRow}).
 *
 * The monopolistic distinction rides as the string {@see $type} where {@see LogWorkerSummary}
 * carries a bool. The wire shape repeats the neighbouring key table's `class`, so that one field
 * feeds the badge, the filter key and a third button the section may one day want; a bool would
 * give the filter a language of its own (`type=1`) and the view a second rule to read by.
 */
final class HilosLogWorkersTableRow extends AbstractTableRow
{
    /**
     * Payload key of the row identity (`<node>:<key>`, a dash for the node in a single-node installation).
     *
     * It rides the row fragment's `rowKey`, never a field inside the slot, so the frontend
     * normalizer keeps the row whole ({@see HilosLogWorkersTableRow}).
     */
    public const string rowKey = 'rowKey';

    public const string key = 'key';
    public const string node = 'node';

    /** Payload key of the worker kind, one of the {@see HilosLogWorkersTable} type values. */
    public const string type = 'type';

    public const string live = 'live';
    public const string batchCount = 'batchCount';

    /**
     * Payload key of the newest batch the stream occurs in, null when it occurs in none.
     *
     * What the button into the viewer needs from an archive-only stream: it opens on that batch,
     * because the live file it would otherwise open is a file that is no longer there.
     */
    public const string lastBatchAt = 'lastBatchAt';

    public const string bytes = 'bytes';

    /**
     * @param string $rowKey Stable row key, `<node>:<key>`
     * @param string $key File basename of the stream, stable across rotation batches
     * @param ?string $node Cluster node the file lives on, null in a single-node installation
     * @param string $type Worker kind: {@see HilosLogWorkersTable::TYPE_MONOPOLISTIC} or {@see HilosLogWorkersTable::TYPE_REGULAR}
     * @param bool $live Whether the stream is still being written, or only present in the archive
     * @param int $batchCount Number of archived batches the stream occurs in
     * @param ?int $lastBatchAt Unix timestamp of the newest batch holding the stream, null when there is none
     * @param int $bytes Weight of the live file and every archived occurrence together
     */
    public function __construct(
        public string $rowKey,
        public string $key,
        public ?string $node,
        public string $type,
        public bool $live,
        public int $batchCount,
        public ?int $lastBatchAt,
        public int $bytes,
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
     * Serializes the row to the log-workers table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            // The key rides the payload under a name the normalizer ignores; `id` would make
            // the whole slot look like an entity fragment on the frontend.
            self::rowKey => $this->rowKey,
            self::key => $this->key,
            self::node => $this->node,
            self::type => $this->type,
            self::live => $this->live,
            self::batchCount => $this->batchCount,
            self::lastBatchAt => $this->lastBatchAt,
            self::bytes => $this->bytes,
        ];
    }

    /**
     * Builds a log-worker row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed log-workers table row
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            key: self::requireString($data, self::key),
            node: self::optionalString($data, self::node),
            type: self::requireString($data, self::type),
            live: self::requireBool($data, self::live),
            batchCount: self::requireInt($data, self::batchCount),
            lastBatchAt: self::optionalInt($data, self::lastBatchAt),
            bytes: self::requireInt($data, self::bytes),
        );
    }
}
