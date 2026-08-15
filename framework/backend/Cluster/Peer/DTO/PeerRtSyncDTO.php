<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Throwable;

/**
 * Broadcast frame carrying one RT sync fact to every other node of the mesh.
 *
 * An RT collection has exactly one truth source in the whole cluster, so replication is
 * one-way: the node the write happened on announces the fact, and every other node applies
 * it to its read-only copy. The frame therefore names no target — it goes to every
 * handshaked link — and the receiver never passes it on: one hop, exactly as
 * {@see PeerSignalDTO}, which is what rules out the gossip echo structurally rather than by
 * counting hops.
 *
 * The inner {@see SignalDTO} travels verbatim through its own {@see SignalDTO::toArray()} /
 * {@see SignalDTO::fromArray()}, so the receiving node applies and fans out the very signal
 * its own worker would have produced. {@see $signalType} repeats what that signal carries
 * because the frame is dropped on the reading side before the signal is restored: a frame
 * naming anything but the three RT sync types is not an RT replica at all.
 *
 * {@see $originNodeId} is what a dropped frame is logged with, and what names the other
 * owner when a collection turns out to have a truth source on two nodes.
 */
final class PeerRtSyncDTO extends PeerDTO
{
    /** @var string Wire message type for the RT sync frame */
    public const string MESSAGE_TYPE = 'peer_rt_sync';

    /** @var string Payload key: id of the node the write happened on */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: RT sync signal type the frame carries */
    public const string FIELD_SIGNAL_TYPE = 'signalType';

    /** @var string Payload key: the serialized RT sync signal */
    public const string FIELD_SIGNAL = 'signal';

    /** @var list<string> The only signal types an RT replica frame may carry */
    public const array SIGNAL_TYPES = [
        SignalTypeConstants::RT_SYNC_CREATED,
        SignalTypeConstants::RT_SYNC_UPDATED,
        SignalTypeConstants::RT_SYNC_DELETED,
    ];

    /**
     * @param string $originNodeId Id of the node the write happened on
     * @param string $signalType RT sync signal type the frame carries
     * @param SignalDTO $signal RT sync signal to apply on the receiving node
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
     * Serializes the RT sync frame to its wire array.
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
     * Restores an RT sync frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored RT sync frame
     * @throws PeerTransportException When the origin is missing, the type is not an RT sync
     *     one, or the inner signal is malformed
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer RT sync is missing the origin node id');
        }

        $signalType = $data[self::FIELD_SIGNAL_TYPE] ?? null;
        if (!is_string($signalType) || !in_array($signalType, self::SIGNAL_TYPES, true)) {
            throw new PeerTransportException('Peer RT sync carries a signal type that is not an RT sync one');
        }

        $signalRaw = $data[self::FIELD_SIGNAL] ?? null;
        if (!is_array($signalRaw)) {
            throw new PeerTransportException('Peer RT sync is missing the inner signal payload');
        }

        try {
            $signal = SignalDTO::fromArray($signalRaw);
        } catch (Throwable $e) {
            throw new PeerTransportException('Peer RT sync carries a malformed inner signal: ' . $e->getMessage());
        }

        return new static(
            originNodeId: $originNodeId,
            signalType: $signalType,
            signal: $signal,
        );
    }
}
