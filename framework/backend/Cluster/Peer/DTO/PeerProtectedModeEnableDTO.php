<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;

/**
 * Peer frame an initiator node sends to the leader to request a protected-mode freeze.
 *
 * The initiator (the backup restore agent today, other destructive operations later) cannot
 * reach the leader through the agent-signal fabric — a worker-sent agent signal only ever
 * lands on another worker, never on the leader daemon — so the request rides the peer channel
 * instead. The initiator node forwards this frame to the leader with {@see PeerServer::sendToMaster};
 * the leader records the carried {@see ProtectedModeEnableSignalData} on
 * {@see \Hilos\Runtime\State\Item\ProtectedModeRuntime}, drives the two-phase freeze, and answers
 * with a {@see PeerProtectedModeReadyDTO} once every node has quiesced. The frame is a thin
 * transport envelope; the contract-gated field shape lives in the wrapped payload.
 */
final class PeerProtectedModeEnableDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode enable frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_enable';

    /** @var string Envelope key carrying the enable payload */
    public const string FIELD_PAYLOAD = 'payload';

    /**
     * @param ProtectedModeEnableSignalData $data Initiator identity and operation the freeze protects
     */
    public function __construct(
        public readonly ProtectedModeEnableSignalData $data,
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
     * Serializes the enable frame to its wire array.
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
     * Restores an enable frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the enable payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new PeerTransportException('Peer protected-mode enable frame carries a non-object payload');
        }

        return new static(ProtectedModeEnableSignalData::fromArray($payload));
    }
}
