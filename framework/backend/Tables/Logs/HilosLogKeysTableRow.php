<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\LogKeySummary;

/**
 * Backend row payload for the framework log-keys table (HIL-385).
 *
 * One row is one log key ON ONE NODE, projected from the {@see LogKeySummary} that node reported
 * into {@see ClusterLogIndexMirror}. The same `worker-0.log` on two nodes is two files on two
 * machines, rotated and carried off apart, and folding them by name would understate both the
 * weight and the count.
 *
 * The key identity rides the row fragment's {@see rowKey}, never a field named `id` inside the
 * slot: a slot payload carrying `id` is ingested by the frontend normalizer as an entity fragment
 * and replaced with a reference, which would strip every other field off this row
 * ({@see AbstractTableRow}).
 *
 * The daily growth is carried twice on purpose. {@see $growthPerDay} is what the screen draws, and
 * it is null when the measuring window has not filled yet — a dash, because a zero would claim the
 * stream is not growing. {@see $growthSort} is what the window orders by, with the unknown minted
 * as -1 so it sinks to the bottom of a descending sort: {@see InMemoryTableFilter} puts a null on
 * TOP when ordering descending, which would open the column with the rows nothing is known about.
 */
final class HilosLogKeysTableRow extends AbstractTableRow
{
    /**
     * Payload key of the row identity (`<node>:<key>`, a dash for the node in a single-node installation).
     *
     * It rides the row fragment's `rowKey`, never a field inside the slot, so the frontend
     * normalizer keeps the row whole ({@see HilosLogKeysTableRow}).
     */
    public const string rowKey = 'rowKey';

    public const string key = 'key';
    public const string node = 'node';

    /**
     * Payload key of the stream class, one of the {@see LogKeySummary} class values.
     *
     * Named `streamClass` where every other constant here is named after its field, because
     * `class` is reserved as a class-constant name in PHP. The wire key is still `class`.
     */
    public const string streamClass = 'class';

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
    public const string growthPerDay = 'growthPerDay';
    public const string growthSort = 'growthSort';

    /**
     * @param string $rowKey Stable row key, `<node>:<key>`
     * @param string $key File basename of the stream, stable across rotation batches
     * @param ?string $node Cluster node the file lives on, null in a single-node installation
     * @param string $class Stream class: {@see LogKeySummary::CLASS_AGENT} or {@see LogKeySummary::CLASS_WORKER}
     * @param bool $live Whether the stream is still being written, or only present in the archive
     * @param int $batchCount Number of archived batches the stream occurs in
     * @param ?int $lastBatchAt Unix timestamp of the newest batch holding the stream, null when there is none
     * @param int $bytes Weight of the live file and every archived occurrence together
     * @param ?int $growthPerDay Bytes written over the last day, null while the window has not filled
     * @param int $growthSort The same growth as the window orders by, -1 standing for the unknown
     */
    public function __construct(
        public string $rowKey,
        public string $key,
        public ?string $node,
        public string $class,
        public bool $live,
        public int $batchCount,
        public ?int $lastBatchAt,
        public int $bytes,
        public ?int $growthPerDay,
        public int $growthSort,
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
     * Serializes the row to the log-keys table payload shape.
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
            self::streamClass => $this->class,
            self::live => $this->live,
            self::batchCount => $this->batchCount,
            self::lastBatchAt => $this->lastBatchAt,
            self::bytes => $this->bytes,
            self::growthPerDay => $this->growthPerDay,
            self::growthSort => $this->growthSort,
        ];
    }

    /**
     * Builds a log-key row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed log-keys table row
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            key: self::requireString($data, self::key),
            node: self::optionalString($data, self::node),
            class: self::requireString($data, self::streamClass),
            live: self::requireBool($data, self::live),
            batchCount: self::requireInt($data, self::batchCount),
            lastBatchAt: self::optionalInt($data, self::lastBatchAt),
            bytes: self::requireInt($data, self::bytes),
            growthPerDay: self::optionalInt($data, self::growthPerDay),
            growthSort: self::requireInt($data, self::growthSort),
        );
    }
}
