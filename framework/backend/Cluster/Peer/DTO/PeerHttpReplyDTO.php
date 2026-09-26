<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Router\DTO\SignalDTO;
use Throwable;

/**
 * Forward frame carrying an agent's HTTP reply to the node holding the parked connection.
 *
 * The HTTP twin of {@see PeerClientSignalDTO}, built on the same rule: the sending node resolved
 * the target - the node named as the request's origin - and the receiving node checks
 * {@see $targetNodeId} is its own id, then writes the reply to the connection it parked under
 * the reply's correlation id. It never re-routes, so a reply cannot travel on or loop.
 *
 * The inner {@see SignalDTO} is the HTTP_REPLY itself, reused verbatim through its own
 * {@see SignalDTO::toArray()} / {@see SignalDTO::fromArray()}. {@see $originNodeId} is carried
 * for logging and tracing only.
 */
final class PeerHttpReplyDTO extends PeerDTO
{
    /** @var string Wire message type for the HTTP reply-forward frame */
    public const string MESSAGE_TYPE = 'peer_http_reply';

    /** @var string Payload key: id of the node the agent answered on */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: id of the node holding the parked connection */
    public const string FIELD_TARGET_NODE_ID = 'targetNodeId';

    /** @var string Payload key: the serialized HTTP_REPLY signal */
    public const string FIELD_SIGNAL = 'signal';

    /**
     * @param string $originNodeId Id of the node the agent answered on (trace only)
     * @param string $targetNodeId Id of the node holding the parked connection
     * @param SignalDTO $signal HTTP_REPLY signal to write on the target node
     */
    public function __construct(
        public readonly string $originNodeId,
        public readonly string $targetNodeId,
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
            throw new PeerTransportException('Peer HTTP reply is missing the origin node id');
        }

        $targetNodeId = $data[self::FIELD_TARGET_NODE_ID] ?? null;
        if (!is_string($targetNodeId) || $targetNodeId === '') {
            throw new PeerTransportException('Peer HTTP reply is missing the target node id');
        }

        $signalRaw = $data[self::FIELD_SIGNAL] ?? null;
        if (!is_array($signalRaw)) {
            throw new PeerTransportException('Peer HTTP reply is missing the inner signal payload');
        }

        try {
            $signal = SignalDTO::fromArray($signalRaw);
        } catch (Throwable $e) {
            throw new PeerTransportException('Peer HTTP reply carries a malformed inner signal: ' . $e->getMessage());
        }

        return new static(
            originNodeId: $originNodeId,
            targetNodeId: $targetNodeId,
            signal: $signal,
        );
    }
}
