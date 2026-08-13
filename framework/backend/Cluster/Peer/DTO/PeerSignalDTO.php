<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Router\DTO\SignalDTO;
use Throwable;

/**
 * Forward frame carrying one signal to an agent hosted on another node.
 *
 * The sending node resolves the final target ({@see $agentType} + {@see $agentIndex}) and
 * puts it in the frame alongside the serialized application {@see SignalDTO}; the receiving
 * node checks {@see $targetNodeId} matches its own id, then delivers the resolved target
 * directly — it never re-routes, which structurally rules out a forward loop or a second
 * fan-out. Routing is one hop: the sender resolves, the receiver executes; multi-hop is out
 * of scope. The inner {@see SignalDTO} is reused verbatim through its own
 * {@see SignalDTO::toArray()} / {@see SignalDTO::fromArray()}, so the application signal
 * contract is unchanged by this transport.
 *
 * {@see $originNodeId} is carried for logging and tracing only; delivery keys off the target.
 */
final class PeerSignalDTO extends PeerDTO
{
    /** @var string Wire message type for the signal-forward frame */
    public const string MESSAGE_TYPE = 'peer_signal';

    /** @var string Payload key: id of the node that resolved and sent the signal */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: id of the node the signal is addressed to */
    public const string FIELD_TARGET_NODE_ID = 'targetNodeId';

    /** @var string Payload key: resolved target agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Payload key: resolved target agent index, or null for a singleton agent */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /** @var string Payload key: the serialized application signal */
    public const string FIELD_SIGNAL = 'signal';

    /**
     * @param string $originNodeId Id of the node that resolved and sent the signal (trace only)
     * @param string $targetNodeId Id of the node the signal is addressed to
     * @param string $agentType Resolved target agent type
     * @param ?string $agentIndex Resolved target agent index, or null for a singleton agent
     * @param SignalDTO $signal Application signal to deliver on the target node
     */
    public function __construct(
        public readonly string $originNodeId,
        public readonly string $targetNodeId,
        public readonly string $agentType,
        public readonly ?string $agentIndex,
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
            self::FIELD_AGENT_TYPE => $this->agentType,
            self::FIELD_AGENT_INDEX => $this->agentIndex,
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
            throw new PeerTransportException('Peer signal is missing the origin node id');
        }

        $targetNodeId = $data[self::FIELD_TARGET_NODE_ID] ?? null;
        if (!is_string($targetNodeId) || $targetNodeId === '') {
            throw new PeerTransportException('Peer signal is missing the target node id');
        }

        $agentType = $data[self::FIELD_AGENT_TYPE] ?? null;
        if (!is_string($agentType) || $agentType === '') {
            throw new PeerTransportException('Peer signal is missing the target agent type');
        }

        $signalRaw = $data[self::FIELD_SIGNAL] ?? null;
        if (!is_array($signalRaw)) {
            throw new PeerTransportException('Peer signal is missing the inner signal payload');
        }

        try {
            $signal = SignalDTO::fromArray($signalRaw);
        } catch (Throwable $e) {
            throw new PeerTransportException('Peer signal carries a malformed inner signal: ' . $e->getMessage());
        }

        $agentIndex = $data[self::FIELD_AGENT_INDEX] ?? null;

        return new static(
            originNodeId: $originNodeId,
            targetNodeId: $targetNodeId,
            agentType: $agentType,
            agentIndex: is_string($agentIndex) && $agentIndex !== '' ? $agentIndex : null,
            signal: $signal,
        );
    }
}
