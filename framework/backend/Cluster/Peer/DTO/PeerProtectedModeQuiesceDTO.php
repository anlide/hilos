<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame the leader broadcasts to every follower to freeze it for a destructive operation.
 *
 * The cluster-wide half of the two-phase freeze: once an initiator has asked over
 * {@see PeerProtectedModeEnableDTO}, the leader sends this to each follower with
 * {@see PeerServer::broadcastToMasters}. The follower quiesces its own agents (leaving the
 * initiator agent named in the carried {@see ProtectedModeQuiesceData} running), writes the
 * freeze onto {@see ProtectedModeRuntime} locally, and answers with a
 * {@see PeerProtectedModeQuiescedDTO}. The frame is a thin transport envelope; the freeze
 * descriptor lives in the wrapped payload.
 */
final class PeerProtectedModeQuiesceDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode quiesce frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_quiesce';

    /** @var string Envelope key carrying the quiesce payload */
    public const string FIELD_PAYLOAD = 'payload';

    /**
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the freeze protects
     */
    public function __construct(
        public readonly ProtectedModeQuiesceData $data,
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
     * Serializes the quiesce frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_PAYLOAD => $this->data->toArray(),
        ];
    }

    /**
     * Restores a quiesce frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the quiesce payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new PeerTransportException('Peer protected-mode quiesce frame carries a non-object payload');
        }

        return new static(ProtectedModeQuiesceData::fromArray($payload));
    }
}
