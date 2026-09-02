<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\ClusterLogIndex;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;

/**
 * The catalog of readable sources for the log viewer (server → client, HIL-388).
 *
 * What the viewer's three selects are built from, and nothing else: which nodes have reported,
 * which rotation batches each of them holds, and which streams occur where. The lines themselves
 * ride the `logs_read_lines` action ({@see LogsReadLinesReplyDTO}) and are deliberately not here —
 * one frame carrying both would make every page of lines re-deliver the catalog.
 *
 * The catalog is a projection of {@see ClusterLogIndexMirror}'s picture, so a node that has stopped
 * reporting keeps its entry until the picture itself loses it. {@see $available} has the three
 * answers the overview's and the rotations header's have: null while no merged picture has arrived,
 * false when the picture arrived and not one node could read its store, true when there is
 * something to offer.
 *
 * A node carries no online flag, because the mirror does not know one ({@see ClusterLogNodeSlot}
 * holds an id, an index and an arrival time) and this page reads no cluster roster. A node that is
 * down shows up where it is actually felt — in the refusal of the read
 * ({@see AbstractHilosLogsViewPage}), which the screen shows in the log pane.
 *
 * Every payload key is named after the field it carries. {@see self::streamClass} is the one
 * exception, and not by choice: `class` cannot be a class-constant name in PHP.
 */
final class HilosLogsViewCatalogSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Node key: the node in a single-node installation, which has no id of its own.
     *
     * The empty string, and it is the same key {@see ClusterLogIndex} slots such an installation
     * under. It travels rather than being left out, because the segment is what the reading action
     * is addressed by, and {@see AbstractHilosLogsViewPage} already reads it as "this node".
     */
    public const string SINGLE_NODE_ID = '';

    /** Payload key: whether anything can be read at all, null while no picture has arrived. */
    public const string available = 'available';

    /** Payload key: the nodes the picture holds, in the order it holds them. */
    public const string nodes = 'nodes';

    /** Node row key: cluster node id, {@see self::SINGLE_NODE_ID} in a single-node installation. */
    public const string nodeId = 'nodeId';

    /** Node row key: ascending Unix timestamps of the rotation batches the node holds. */
    public const string batches = 'batches';

    /** Node row key: the streams the node has, live and archived alike. */
    public const string streams = 'streams';

    /** Stream row key: file basename, stable across batches. */
    public const string key = 'key';

    /**
     * Stream row key: stream class, one of the {@see LogKeySummary} class values.
     *
     * Named `streamClass` where every other constant here is named after its field, because
     * `class` is reserved as a class-constant name in PHP. The wire key is still `class`.
     */
    public const string streamClass = 'class';

    /** Stream row key: whether the stream is present among the live (non-archived) files. */
    public const string live = 'live';

    /** Stream row key: ascending Unix timestamps of the batches the stream occurs in. */
    public const string batchTimestamps = 'batchTimestamps';

    /**
     * @param ?bool $available Whether anything can be read, null while no picture has arrived
     * @param list<array{nodeId: string, available: bool, batches: list<int>,
     *     streams: list<array{key: string, class: string, live: bool, batchTimestamps: list<int>}>}> $nodes
     *     The nodes the picture holds, each with its batches and its streams
     */
    public function __construct(
        public readonly ?bool $available,
        public readonly array $nodes,
    ) {
    }

    /**
     * Projects the cluster picture into the catalog the selects are drawn from.
     *
     * @param ?ClusterLogIndex $index Cluster picture, or null while none has arrived
     * @return self Catalog of every node in that picture
     */
    public static function fromIndex(?ClusterLogIndex $index): self
    {
        $slots = $index?->nodes() ?? [];
        $nodes = [];
        foreach ($slots as $slot) {
            $nodes[] = self::nodeToArray($slot);
        }

        return new self(
            available: self::availabilityOf($slots),
            nodes: $nodes,
        );
    }

    /**
     * @return array<string, mixed> Catalog as it goes out to the browser
     */
    public function toArray(): array
    {
        return [
            self::available => $this->available,
            self::nodes => $this->nodes,
        ];
    }

    /**
     * Reads the catalog back from its wire form.
     *
     * A node or a stream missing a field is refused rather than filled in: a catalog that repaired
     * itself here would offer a file under a name no node reported.
     *
     * @param array<string, mixed> $data Wire form of the catalog
     * @return static Restored catalog
     * @throws InvalidFormatException When a row is not an object, or a field the catalog has no
     *     meaning without is absent or holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        $available = $data[self::available] ?? null;
        $nodes = [];
        foreach (self::rows($data, self::nodes) as $row) {
            $streams = [];
            foreach (self::rows($row, self::streams) as $stream) {
                $streams[] = [
                    self::key => self::requireString($stream, self::key),
                    self::streamClass => self::requireString($stream, self::streamClass),
                    self::live => self::requireBool($stream, self::live),
                    self::batchTimestamps => self::timestamps($stream, self::batchTimestamps),
                ];
            }

            $nodes[] = [
                self::nodeId => self::requireString($row, self::nodeId),
                self::available => self::requireBool($row, self::available),
                self::batches => self::timestamps($row, self::batches),
                self::streams => $streams,
            ];
        }

        return new static(
            // Anything that is not a bool reads as null — "we do not know" — rather than as false:
            // false is the claim that every node was heard from and none of them could be read.
            available: is_bool($available) ? $available : null,
            nodes: $nodes,
        );
    }

    /**
     * The three answers to "is there anything to offer".
     *
     * A picture that holds no node is the same answer as no picture at all — nobody has told us
     * anything — and reporting it as false would claim every log store in the cluster was read and
     * found unreadable.
     *
     * @param list<ClusterLogNodeSlot> $slots Slots of the picture, empty while none has arrived
     * @return ?bool True when at least one node can be read, false when none can, null while
     *     nobody has reported
     */
    private static function availabilityOf(array $slots): ?bool
    {
        if ($slots === []) {
            return null;
        }

        foreach ($slots as $slot) {
            if ($slot->index->available) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lays out one node: what it is called, whether it can be read, and what it holds.
     *
     * A node that cannot be read keeps its entry with empty lists rather than being dropped. The
     * select still offers it, and choosing it says why nothing is there — a node silently missing
     * would read as a node that never existed.
     *
     * @param ClusterLogNodeSlot $slot Slot of one node in the cluster picture
     * @return array{nodeId: string, available: bool, batches: list<int>,
     *     streams: list<array{key: string, class: string, live: bool, batchTimestamps: list<int>}>} Node row
     */
    private static function nodeToArray(ClusterLogNodeSlot $slot): array
    {
        return [
            self::nodeId => $slot->nodeId ?? self::SINGLE_NODE_ID,
            self::available => $slot->index->available,
            self::batches => array_map(
                static fn(LogBatchSummary $batch): int => $batch->timestamp,
                $slot->index->batches,
            ),
            self::streams => array_map(
                static fn(LogKeySummary $stream): array => [
                    self::key => $stream->key,
                    self::streamClass => $stream->class,
                    self::live => $stream->live,
                    self::batchTimestamps => $stream->batchTimestamps,
                ],
                $slot->index->keys,
            ),
        ];
    }

    /**
     * Reads one list of rows, refusing anything in it that is not an object.
     *
     * @param array<string, mixed> $data Wire form of the catalog, or of one node of it
     * @param string $listKey Payload key holding the list
     * @return list<array<string, mixed>> Rows of that list
     * @throws InvalidFormatException When the list is absent or carries a row that is not an object
     */
    private static function rows(array $data, string $listKey): array
    {
        $rows = [];
        foreach (self::requireArray($data, $listKey) as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Log viewer catalog carries a row that is not an object under key ' . $listKey);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Reads a list of Unix timestamps, refusing anything in it that is not one.
     *
     * @param array<string, mixed> $data Node row or stream row from the wire
     * @param string $listKey Payload key holding the list
     * @return list<int> Timestamps, in the order the row lists them
     * @throws InvalidFormatException When the list is absent or carries something that is not a timestamp
     */
    private static function timestamps(array $data, string $listKey): array
    {
        $timestamps = [];
        foreach (self::requireArray($data, $listKey) as $timestamp) {
            if (!is_int($timestamp)) {
                throw new InvalidFormatException('Log viewer catalog carries a timestamp that is not an integer under key ' . $listKey);
            }

            $timestamps[] = $timestamp;
        }

        return $timestamps;
    }
}
