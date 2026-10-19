<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;

/** Absolute upper bound on unused capacity; received portions survive. */
final class PeerUploadReleaseDTO extends PeerDTO
{
    public const string MESSAGE_TYPE = 'peer_upload_release';

    /**
     * @param string $reservationId Link-scoped identity
     * @param int $remainingBytes Raw byte count
     * @throws PeerTransportException When identity or byte count is invalid
     */
    public function __construct(
        public readonly string $reservationId,
        public readonly int $remainingBytes,
    ) {
        if ($reservationId === '' || $remainingBytes < 0) {
            throw new PeerTransportException('Invalid upload release identity or byte count');
        }
    }

    /** @return string Upload control frame type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Peer wire boundary */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            'reservationId' => $this->reservationId,
            'remainingBytes' => $this->remainingBytes,
        ];
    }

    /**
     * @param array<string, mixed> $data Peer wire boundary
     * @return static Validated upload control frame
     * @throws PeerTransportException When required fields are absent, mistyped or out of range
     */
    public static function fromArray(array $data): static
    {
        try {
            return new static(
                self::requireString($data, 'reservationId'),
                self::requireInt($data, 'remainingBytes'),
            );
        } catch (InvalidFormatException $e) {
            throw new PeerTransportException('Malformed upload release: ' . $e->getMessage());
        }
    }
}
