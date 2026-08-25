<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Throwable;

/**
 * Broadcast frame carrying one DB sync fact to every other node of the mesh.
 *
 * The twin of {@see PeerRtSyncDTO} for rows that live in the database rather than in runtime
 * state, and it exists because the shared database is not what the nodes read from: each one
 * keeps rows in memory, and a row another node changed stayed as this node first read it,
 * forever. What travels is the fact itself - created, updated, deleted, cleared, plus the row -
 * and it is the very fact this node's own workers are told, so a receiving node applies what
 * the writing node's worker would have applied.
 *
 * The frame names no target and the receiver never passes it on: one hop, exactly as
 * {@see PeerRtSyncDTO} and {@see PeerSignalDTO}, which rules out the echo structurally rather
 * than by counting hops.
 *
 * {@see $signalType} repeats what the inner signal carries because the frame is judged before
 * the signal is restored: a frame naming anything but the four DB sync types is not a DB
 * replica at all. {@see $originNodeId} is what a dropped frame is logged with, and what marks
 * the fact as somebody else's on the whole path through this node - which is what lets a
 * created row be taken by a collection that holds the full set and skipped by one that does
 * not.
 *
 * There is no partial-owner mark and no ownership check on receipt, unlike the RT twin: the
 * write already happened in the one database both nodes read, so refusing the fact would not
 * undo it - it would only leave this node's copy disagreeing with the row on disk. The guard
 * that matters for the database stands at the writer.
 */
final class PeerDbSyncDTO extends PeerDTO
{
    /** @var string Wire message type for the DB sync frame */
    public const string MESSAGE_TYPE = 'peer_db_sync';

    /** @var string Payload key: id of the node the write happened on */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: DB sync signal type the frame carries */
    public const string FIELD_SIGNAL_TYPE = 'signalType';

    /** @var string Payload key: the serialized DB sync signal */
    public const string FIELD_SIGNAL = 'signal';

    /**
     * @var list<string> The only signal types a DB replica frame may carry.
     *
     * The re-hydrate fact is deliberately absent: replacing the database under a live node is a
     * restore, which has a peer protocol and a barrier of its own, and carrying it here would
     * announce the replacement a second time with nothing waiting on the answer.
     */
    public const array SIGNAL_TYPES = [
        SignalTypeConstants::DB_SYNC_CREATED,
        SignalTypeConstants::DB_SYNC_UPDATED,
        SignalTypeConstants::DB_SYNC_DELETED,
        SignalTypeConstants::DB_SYNC_CLEARED,
    ];

    /**
     * @param string $originNodeId Id of the node the write happened on
     * @param string $signalType DB sync signal type the frame carries
     * @param SignalDTO $signal DB sync signal to apply on the receiving node
     */
    public function __construct(
        public readonly string $originNodeId,
        public readonly string $signalType,
        public readonly SignalDTO $signal,
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
     * Serializes the DB sync frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
            self::FIELD_SIGNAL_TYPE => $this->signalType,
            self::FIELD_SIGNAL => $this->signal->toArray(),
        ];
    }

    /**
     * Restores a DB sync frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored DB sync frame
     * @throws PeerTransportException When the origin is missing, the type is not a DB sync one,
     *     or the inner signal is malformed
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer DB sync is missing the origin node id');
        }

        $signalType = $data[self::FIELD_SIGNAL_TYPE] ?? null;
        if (!is_string($signalType) || !in_array($signalType, self::SIGNAL_TYPES, true)) {
            throw new PeerTransportException('Peer DB sync carries a signal type that is not a DB sync one');
        }

        $signalRaw = $data[self::FIELD_SIGNAL] ?? null;
        if (!is_array($signalRaw)) {
            throw new PeerTransportException('Peer DB sync is missing the inner signal payload');
        }

        try {
            $signal = SignalDTO::fromArray($signalRaw);
        } catch (Throwable $e) {
            throw new PeerTransportException('Peer DB sync carries a malformed inner signal: ' . $e->getMessage());
        }

        return new static(
            originNodeId: $originNodeId,
            signalType: $signalType,
            signal: $signal,
        );
    }
}
