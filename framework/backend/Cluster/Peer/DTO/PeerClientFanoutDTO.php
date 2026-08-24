<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Router\DTO\SignalDTO;
use Throwable;

/**
 * Broadcast frame asking every other node to fan one signal out to its own browsers.
 *
 * Where {@see PeerClientSignalDTO} carries a resolved address, this one carries an
 * unresolved job, and that difference is the whole design: which browsers a fan-out reaches
 * is answered by the node-local subscription registry, so only the node holding a connection
 * can say whether it belongs in the fan-out. The sending node therefore resolves nothing on
 * anyone's behalf — it names the signal and lets each receiver expand it against its own
 * registry.
 *
 * Nothing describes the job beyond the signal itself: the fan-out kind (ws_all, ws_group,
 * ws_all_connected), the target group and the excluded accept key are already inside the
 * signal's WebSocketSignalData, and the receiving node reads them from there through the
 * very resolution its own worker would have triggered.
 *
 * The receiver expands the job locally and passes it to nobody: one hop, the same structural
 * echo defense {@see PeerRtSyncDTO} and {@see PeerSignalDTO} stand on. Duplicates are ruled
 * out by the accept key belonging to exactly one node for its whole life, so no receiver can
 * claim a browser another one also serves.
 *
 * {@see $originNodeId} is what a dropped frame is logged with; delivery keys off nothing.
 */
final class PeerClientFanoutDTO extends PeerDTO
{
    /** @var string Wire message type for the client fan-out frame */
    public const string MESSAGE_TYPE = 'peer_client_fanout';

    /** @var string Payload key: id of the node the fan-out started on */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: the serialized application signal */
    public const string FIELD_SIGNAL = 'signal';

    /**
     * @param string $originNodeId Id of the node the fan-out started on (trace only)
     * @param SignalDTO $signal Application signal each node expands against its own registry
     */
    public function __construct(
        public readonly string $originNodeId,
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
     * Serializes the fan-out frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
            self::FIELD_SIGNAL => $this->signal->toArray(),
        ];
    }

    /**
     * Restores a fan-out frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored fan-out frame
     * @throws PeerTransportException When the origin id is missing or the inner signal is malformed
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer client fanout is missing the origin node id');
        }

        $signalRaw = $data[self::FIELD_SIGNAL] ?? null;
        if (!is_array($signalRaw)) {
            throw new PeerTransportException('Peer client fanout is missing the inner signal payload');
        }

        try {
            $signal = SignalDTO::fromArray($signalRaw);
        } catch (Throwable $e) {
            throw new PeerTransportException('Peer client fanout carries a malformed inner signal: ' . $e->getMessage());
        }

        return new static(
            originNodeId: $originNodeId,
            signal: $signal,
        );
    }
}
