<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;

/**
 * Peer frame the initiator node sends to the leader to lift the freeze cluster-wide.
 *
 * Sent once the initiator's destructive operation has finished: the initiator node forwards it
 * to the leader with {@see PeerServer::sendToMaster}, and the leader lifts the mode it currently
 * owns on {@see \Hilos\Runtime\State\Item\ProtectedModeRuntime} and broadcasts the lift to the
 * followers. There is only ever one freeze in flight, so the carried
 * {@see ProtectedModeDisableSignalData} is empty; the frame itself is the disable request.
 */
final class PeerProtectedModeDisableDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode disable frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_disable';

    /** @var string Envelope key carrying the disable payload */
    public const string FIELD_PAYLOAD = 'payload';

    /**
     * @param ProtectedModeDisableSignalData $data Empty disable payload
     */
    public function __construct(
        public readonly ProtectedModeDisableSignalData $data = new ProtectedModeDisableSignalData(),
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
     * Serializes the disable frame to its wire array.
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
     * Restores a disable frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the disable payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new PeerTransportException('Peer protected-mode disable frame carries a non-object payload');
        }

        return new static(ProtectedModeDisableSignalData::fromArray($payload));
    }
}
