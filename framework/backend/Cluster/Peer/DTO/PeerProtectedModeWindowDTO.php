<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;

/**
 * The leader sends this frame to every follower master through {@see PeerServer::broadcastToMasters}.
 * Each follower writes a direct verification window without stopping its agents and answers with
 * {@see PeerProtectedModeQuiescedDTO}. The existing freeze descriptor is carried unchanged.
 */
final class PeerProtectedModeWindowDTO extends PeerDTO
{
    /** @var string Wire message type for the direct verification window */
    public const string MESSAGE_TYPE = 'peer_protected_mode_window';

    /** @var string Envelope key carrying the freeze descriptor */
    public const string FIELD_PAYLOAD = 'payload';

    /**
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the window protects
     */
    public function __construct(
        public readonly ProtectedModeQuiesceData $data,
    ) {
    }

    /**
     * @return string Wire message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
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
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the window payload is not an object or is incomplete
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new PeerTransportException('Peer protected-mode window frame carries a non-object payload');
        }

        try {
            return new static(ProtectedModeQuiesceData::fromArray($payload));
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException(
                'Peer protected-mode window frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }
}
