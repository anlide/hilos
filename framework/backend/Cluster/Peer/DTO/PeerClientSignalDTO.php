<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Router\DTO\SignalDTO;
use Throwable;

/**
 * Forward frame carrying one signal to a browser attached to another node.
 *
 * The browser-side twin of {@see PeerSignalDTO}, and built on the same rule: the sending node
 * resolves the final target — here the {@see $acceptKey} of the connection — and the receiving
 * node checks {@see $targetNodeId} matches its own id, then writes to that socket. It never
 * re-routes, which structurally rules out a forward loop or a second fan-out. Routing is one
 * hop: the sender resolves, the receiver executes.
 *
 * The inner {@see SignalDTO} is reused verbatim through its own {@see SignalDTO::toArray()} /
 * {@see SignalDTO::fromArray()}, so the receiving node encodes the very frame its own local
 * path would have encoded, and the application signal contract is unchanged by this transport.
 *
 * {@see $originNodeId} is carried for logging and tracing only; delivery keys off the target.
 */
final class PeerClientSignalDTO extends PeerDTO
{
    /** @var string Wire message type for the client signal-forward frame */
    public const string MESSAGE_TYPE = 'peer_client_signal';

    /** @var string Payload key: id of the node that resolved and sent the signal */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: id of the node holding the connection */
    public const string FIELD_TARGET_NODE_ID = 'targetNodeId';

    /** @var string Payload key: accept key of the connection to deliver to */
    public const string FIELD_ACCEPT_KEY = 'acceptKey';

    /** @var string Payload key: the serialized application signal */
    public const string FIELD_SIGNAL = 'signal';

    /**
     * @param string $originNodeId Id of the node that resolved and sent the signal (trace only)
     * @param string $targetNodeId Id of the node holding the connection
     * @param string $acceptKey Accept key of the connection to deliver to
     * @param SignalDTO $signal Application signal to deliver on the target node
     */
    public function __construct(
        public readonly string $originNodeId,
        public readonly string $targetNodeId,
        public readonly string $acceptKey,
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
     * Serializes the forward frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
            self::FIELD_TARGET_NODE_ID => $this->targetNodeId,
            self::FIELD_ACCEPT_KEY => $this->acceptKey,
            self::FIELD_SIGNAL => $this->signal->toArray(),
        ];
    }

    /**
     * Restores a forward frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored forward frame
     * @throws PeerTransportException When an id is missing or the inner signal is malformed
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer client signal is missing the origin node id');
        }

        $targetNodeId = $data[self::FIELD_TARGET_NODE_ID] ?? null;
        if (!is_string($targetNodeId) || $targetNodeId === '') {
            throw new PeerTransportException('Peer client signal is missing the target node id');
        }

        $acceptKey = $data[self::FIELD_ACCEPT_KEY] ?? null;
        if (!is_string($acceptKey) || $acceptKey === '') {
            throw new PeerTransportException('Peer client signal is missing the target accept key');
        }

        $signalRaw = $data[self::FIELD_SIGNAL] ?? null;
        if (!is_array($signalRaw)) {
            throw new PeerTransportException('Peer client signal is missing the inner signal payload');
        }

        try {
            $signal = SignalDTO::fromArray($signalRaw);
        } catch (Throwable $e) {
            throw new PeerTransportException('Peer client signal carries a malformed inner signal: ' . $e->getMessage());
        }

        return new static(
            originNodeId: $originNodeId,
            targetNodeId: $targetNodeId,
            acceptKey: $acceptKey,
            signal: $signal,
        );
    }
}
