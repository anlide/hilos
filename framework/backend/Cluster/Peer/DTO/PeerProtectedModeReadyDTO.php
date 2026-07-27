<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\ProtectedMode\DTO\ProtectedModeReadySignalData;

/**
 * Peer frame the leader sends back to the initiator node once the freeze is active.
 *
 * The mirror of {@see PeerProtectedModeEnableDTO}: after every node has reported quiesced and
 * {@see \Hilos\Runtime\State\Item\ProtectedModeRuntime} has reached the active phase, the leader
 * routes this frame to the initiator node with {@see PeerServer::sendToNode} — the go-ahead for
 * the initiator to run its destructive operation. The freeze is already fully described by the
 * runtime item, so the carried {@see ProtectedModeReadySignalData} is empty; the frame itself is
 * the readiness.
 */
final class PeerProtectedModeReadyDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode ready frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_ready';

    /** @var string Envelope key carrying the ready payload */
    public const string FIELD_PAYLOAD = 'payload';

    /**
     * @param ProtectedModeReadySignalData $data Empty readiness payload
     */
    public function __construct(
        public readonly ProtectedModeReadySignalData $data = new ProtectedModeReadySignalData(),
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
     * Serializes the ready frame to its wire array.
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
     * Restores a ready frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the ready payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new PeerTransportException('Peer protected-mode ready frame carries a non-object payload');
        }

        return new static(ProtectedModeReadySignalData::fromArray($payload));
    }
}
