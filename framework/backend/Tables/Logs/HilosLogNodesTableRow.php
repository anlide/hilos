<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Log\ClusterLogNodeSlot;

/**
 * Shared projection of one node's log figures, used by the window and the full overview header.
 *
 * The inline slot keeps its identity under nodeId: an id field would make the frontend
 * normalizer replace the whole fragment with an entity reference.
 */
final class HilosLogNodesTableRow extends AbstractTableRow
{
    public const string nodeId = 'nodeId';
    public const string available = 'available';
    public const string lastRotationAt = 'lastRotationAt';
    public const string liveBytes = 'liveBytes';
    public const string archiveBytes = 'archiveBytes';
    public const string growthBytesPerDay = 'growthBytesPerDay';
    public const string batchesDueForTakeout = 'batchesDueForTakeout';

    /**
     * @param string $nodeId Stable node identity and row key
     * @param bool $available Whether the node could read its log store
     * @param ?int $lastRotationAt Newest rotation in UTC epoch milliseconds
     * @param ?int $liveBytes Weight of all live stream classes
     * @param ?int $archiveBytes Weight of all archived stream classes
     * @param ?int $growthBytesPerDay Daily growth, null until any stream's day window fills
     * @param ?int $batchesDueForTakeout Existing recommended batches not confirmed as carried off
     */
    public function __construct(
        public string $nodeId,
        public bool $available,
        public ?int $lastRotationAt,
        public ?int $liveBytes,
        public ?int $archiveBytes,
        public ?int $growthBytesPerDay,
        public ?int $batchesDueForTakeout,
    ) {
    }

    /**
     * Projects only the reported picture; no clock, filesystem or second store is consulted.
     * An unreadable store keeps its name and leaves every measurement unknown.
     *
     * @param ClusterLogNodeSlot $slot Node's place in the cluster mirror
     * @return self Node figures shared by both overview transports
     */
    public static function fromNode(ClusterLogNodeSlot $slot): self
    {
        if (!$slot->index->available) {
            return new self($slot->nodeId, false, null, null, null, null, null);
        }

        $lastRotationAt = null;
        $archiveBytes = 0;
        $batchesDueForTakeout = 0;
        $due = array_flip($slot->index->dueBatchTimestamps);
        foreach ($slot->index->batches as $batch) {
            if ($lastRotationAt === null || $batch->timestamp > $lastRotationAt) {
                $lastRotationAt = $batch->timestamp;
            }
            $archiveBytes += $batch->agentBytes + $batch->workerBytes + $batch->workerMonopolisticBytes + $batch->daemonBytes;
            // Only existing, unconfirmed batches answer the rotations screen's awaiting filter.
            if ($batch->takenAt === null && isset($due[$batch->timestamp])) {
                $batchesDueForTakeout++;
            }
        }

        $totalBytes = 0;
        foreach ($slot->index->keys as $key) {
            $totalBytes += $key->totalBytes;
        }

        $growthBytesPerDay = null;
        foreach ($slot->index->growthBytesPerDay as $bytes) {
            if ($bytes !== null) {
                $growthBytesPerDay = ($growthBytesPerDay ?? 0) + $bytes;
            }
        }

        return new self(
            nodeId: $slot->nodeId,
            available: true,
            lastRotationAt: $lastRotationAt === null ? null : $lastRotationAt * TimeConstants::MS_PER_SECOND,
            // A key summary includes live and archive; both sums include all four stream classes.
            liveBytes: $totalBytes - $archiveBytes,
            archiveBytes: $archiveBytes,
            growthBytesPerDay: $growthBytesPerDay,
            batchesDueForTakeout: $batchesDueForTakeout,
        );
    }

    /**
     * @param array<string, mixed> $data Inline node payload with epoch-millisecond moments
     * @return static Reconstructed node row
     * @throws InvalidFormatException When a required row field is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            nodeId: self::requireString($data, self::nodeId),
            available: self::requireBool($data, self::available),
            lastRotationAt: self::optionalInt($data, self::lastRotationAt),
            liveBytes: self::optionalInt($data, self::liveBytes),
            archiveBytes: self::optionalInt($data, self::archiveBytes),
            growthBytesPerDay: self::optionalInt($data, self::growthBytesPerDay),
            batchesDueForTakeout: self::optionalInt($data, self::batchesDueForTakeout),
        );
    }

    /** @return string Payload field carrying the node's row key */
    public static function keyField(): string
    {
        return self::nodeId;
    }

    /** @return string Stable node identity */
    public function getRowKey(): string
    {
        return $this->nodeId;
    }

    /** @return array<string, mixed> Inline node figures, with no entity id */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::available => $this->available,
            self::lastRotationAt => $this->lastRotationAt,
            self::liveBytes => $this->liveBytes,
            self::archiveBytes => $this->archiveBytes,
            self::growthBytesPerDay => $this->growthBytesPerDay,
            self::batchesDueForTakeout => $this->batchesDueForTakeout,
        ];
    }
}
