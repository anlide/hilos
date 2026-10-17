<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Immutable complete placement registry at one leader term and revision, including cancellation tombstones.
 * An empty records list is an authoritative empty registry, distinct from having no snapshot.
 */
final readonly class PlacementSnapshot
{
    /** @var string Wire key: leader that owns the snapshot */
    public const string FIELD_LEADER_NODE_ID = 'leaderNodeId';

    /** @var string Wire key: leader term */
    public const string FIELD_TERM = 'term';

    /** @var string Wire key: registry revision */
    public const string FIELD_REVISION = 'revision';

    /** @var string Wire key: complete ordered list of registry records */
    public const string FIELD_RECORDS = 'records';

    /**
     * @param string $leaderNodeId Non-blank leader node id
     * @param int $term Nonnegative leader term
     * @param int $revision Nonnegative registry revision
     * @param list<PlacementRecord> $records Complete registry, with unique agent ids
     * @throws PeerTransportException When metadata is invalid, records are not a typed list, or agent ids repeat
     */
    public function __construct(
        public string $leaderNodeId,
        public int $term,
        public int $revision,
        public array $records,
    ) {
        if (trim($leaderNodeId) === '' || $term < 0 || $revision < 0) {
            throw new PeerTransportException('Placement snapshot has invalid leader, term or revision');
        }
        if (!array_is_list($records)) {
            throw new PeerTransportException('Placement snapshot records are not a list');
        }

        $agentIds = [];
        foreach ($records as $record) {
            if (!$record instanceof PlacementRecord) {
                throw new PeerTransportException('Placement snapshot carries a record that is not a PlacementRecord');
            }
            $agentId = $record->agentId();
            if (isset($agentIds[$agentId])) {
                throw new PeerTransportException("Placement snapshot repeats agent '{$agentId}'");
            }
            $agentIds[$agentId] = true;
        }
    }

    /**
     * Rejects the complete snapshot if any record is malformed; no partial list escapes.
     *
     * @param array<string, mixed> $data Snapshot payload decoded as an associative array
     * @return self Restored complete snapshot
     * @throws PeerTransportException When metadata or a record is missing, malformed, or repeats an agent id
     */
    public static function fromArray(array $data): self
    {
        $leaderNodeId = $data[self::FIELD_LEADER_NODE_ID] ?? null;
        $term = $data[self::FIELD_TERM] ?? null;
        $revision = $data[self::FIELD_REVISION] ?? null;
        if (!is_string($leaderNodeId) || !is_int($term) || !is_int($revision)) {
            throw new PeerTransportException('Placement snapshot is missing a string leader id or integer term and revision');
        }

        $rawRecords = $data[self::FIELD_RECORDS] ?? null;
        if (!is_array($rawRecords) || !array_is_list($rawRecords)) {
            throw new PeerTransportException('Placement snapshot records are not a list');
        }

        $records = [];
        foreach ($rawRecords as $rawRecord) {
            if (!is_array($rawRecord)) {
                throw new PeerTransportException('Placement snapshot carries a record that is not an object');
            }
            $records[] = PlacementRecord::fromArray($rawRecord);
        }

        return new self($leaderNodeId, $term, $revision, $records);
    }

    /**
     * @return array<string, mixed> Complete snapshot payload, including an empty records list
     */
    public function toArray(): array
    {
        return [
            self::FIELD_LEADER_NODE_ID => $this->leaderNodeId,
            self::FIELD_TERM => $this->term,
            self::FIELD_REVISION => $this->revision,
            self::FIELD_RECORDS => array_map(static fn(PlacementRecord $record): array => $record->toArray(), $this->records),
        ];
    }
}
