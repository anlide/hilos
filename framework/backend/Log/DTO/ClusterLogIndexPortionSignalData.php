<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\ClusterLogIndex;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\LogAggregatorAgent;

/**
 * {@see LogAggregatorAgent} → {@see AbstractHilosLogsAgent} payload for the
 * logs_cluster_index_portion signal (HIL-756).
 *
 * A portion of the cluster picture: whole node slots, never a line-level difference. Which slots
 * are in it is the aggregator's decision - everything on the first claim from a subscriber, and
 * afterwards only what changed since that subscriber was last written to - and {@see $snapshot}
 * is how the receiver tells the two apart: a snapshot REPLACES the mirror, a portion is laid over
 * it slot by slot. Without that flag a picture could never lose a node, because a portion missing
 * a slot and a snapshot missing one look the same on the wire.
 *
 * The index inside each slot is laid out by {@see NodeLogIndexSignalData} and not by this class:
 * the fields of a node's index already have exactly one wire form, and a second one here would be
 * the same contract written twice, free to drift on the day somebody adds a field to only one.
 */
final class ClusterLogIndexPortionSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: whether the frame replaces the receiver's whole picture rather than adding to it. */
    public const string snapshot = 'snapshot';

    /** Payload key: the node slots this frame carries, each one whole. */
    public const string nodes = 'nodes';

    /** Slot row key: Unix timestamp at which the aggregator heard from that node. */
    public const string receivedAt = 'receivedAt';

    /** Slot row key: that node's index, in the form {@see NodeLogIndexSignalData} gives it. */
    public const string index = 'index';

    /**
     * @param bool $snapshot Whether the frame replaces the receiver's whole picture
     * @param list<ClusterLogNodeSlot> $nodes Node slots this frame carries, each one whole
     */
    public function __construct(
        public readonly bool $snapshot,
        public readonly array $nodes,
    ) {
    }

    /**
     * Wraps the slots the aggregator decided to send for the trip to a subscriber.
     *
     * @param list<ClusterLogNodeSlot> $slots Slots to carry, each one whole
     * @param bool $snapshot Whether these slots are the whole picture rather than what changed
     * @return self Payload carrying those slots
     */
    public static function ofSlots(array $slots, bool $snapshot): self
    {
        return new self(snapshot: $snapshot, nodes: $slots);
    }

    /**
     * @return array<string, mixed> Portion as it goes out to the subscriber
     */
    public function toArray(): array
    {
        return [
            self::snapshot => $this->snapshot,
            self::nodes => array_map(
                static fn (ClusterLogNodeSlot $slot): array => [
                    self::receivedAt => $slot->receivedAt,
                    self::index => NodeLogIndexSignalData::fromIndex($slot->index)->toArray(),
                ],
                $this->nodes,
            ),
        ];
    }

    /**
     * Reads a portion back from its wire form.
     *
     * A row that is not an object, and a field the frame has no meaning without, are refused rather
     * than filled in: a slot repaired here would put figures in a screen's picture that no node
     * ever measured. Which node a slot belongs to is not read at this level - it is inside the
     * index, where the node itself wrote it.
     *
     * @param array<string, mixed> $data Wire form of one portion
     * @return static Restored payload
     * @throws InvalidFormatException When a row is not an object, or a field the frame has no
     *     meaning without is absent or holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        $slots = [];
        foreach (self::requireArray($data, self::nodes) as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Cluster log index portion carries a node that is not an object');
            }

            $index = NodeLogIndexSignalData::fromArray(self::requireArray($row, self::index))->toIndex();
            $slots[] = new ClusterLogNodeSlot(
                nodeId: $index->nodeId,
                index: $index,
                receivedAt: self::requireInt($row, self::receivedAt),
            );
        }

        return new static(
            snapshot: self::requireBool($data, self::snapshot),
            nodes: $slots,
        );
    }

    /**
     * The slots this frame carries, as the receiver files them.
     *
     * @return list<ClusterLogNodeSlot> Slots, each one whole
     */
    public function toSlots(): array
    {
        return $this->nodes;
    }

    /**
     * The picture these slots make on their own, for the frame that replaces a mirror whole.
     *
     * Built here rather than by the receiver looping over {@see self::toSlots()}, because "a
     * snapshot is the whole picture" is a statement about this frame and belongs where the frame
     * is described. A portion has no business calling it: laying a portion over
     * {@see ClusterLogIndex::empty()} would silently drop every node the frame did not mention.
     *
     * @return ClusterLogIndex Picture holding exactly the slots of this frame
     */
    public function toIndex(): ClusterLogIndex
    {
        $index = ClusterLogIndex::empty();
        foreach ($this->nodes as $slot) {
            $index = $index->withNode($slot);
        }

        return $index;
    }
}
