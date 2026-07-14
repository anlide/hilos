<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Graceful-leave frame a node broadcasts before a planned shutdown.
 *
 * A planned stop is announced (this frame); a crash is silence — so peers can tell
 * an orderly departure from a failure and skip the failover panic for the former.
 * A leaving leader also names its most-recently-heard follower as
 * {@see $designatedSuccessor}: that node campaigns immediately (raft TimeoutNow-style)
 * while the others keep waiting their randomized timeout, so leadership transfers
 * without an election-timeout wait and without a split vote. Fire-and-forget: a
 * recipient marks the leaving node offline at once, and the ordinary election
 * (HIL-339) remains the fallback if the successor never takes over.
 */
final class PeerNodeLeavingDTO extends PeerDTO
{
    /** @var string Wire message type for the graceful-leave frame */
    public const string MESSAGE_TYPE = 'peer_node_leaving';

    /** @var string Payload key: leaving node id */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: whether the leaving node was the leader */
    public const string FIELD_WAS_LEADER = 'wasLeader';

    /** @var string Payload key: designated successor node id, or null */
    public const string FIELD_DESIGNATED_SUCCESSOR = 'designatedSuccessor';

    /**
     * @param string $nodeId Leaving node id
     * @param bool $wasLeader Whether the leaving node held leadership
     * @param ?string $designatedSuccessor Successor named by a leaving leader, or null
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly bool $wasLeader,
        public readonly ?string $designatedSuccessor,
    ) {
    }

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Serializes the graceful-leave frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_WAS_LEADER => $this->wasLeader,
            self::FIELD_DESIGNATED_SUCCESSOR => $this->designatedSuccessor,
        ];
    }

    /**
     * Restores a graceful-leave frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the leaving node id is missing
     */
    public static function fromArray(array $data): static
    {
        $nodeId = trim((string)($data[self::FIELD_NODE_ID] ?? ''));
        if ($nodeId === '') {
            throw new PeerTransportException('Peer node-leaving frame is missing the node id');
        }

        $successor = trim((string)($data[self::FIELD_DESIGNATED_SUCCESSOR] ?? ''));

        return new static(
            nodeId: $nodeId,
            wasLeader: (bool)($data[self::FIELD_WAS_LEADER] ?? false),
            designatedSuccessor: $successor === '' ? null : $successor,
        );
    }
}
