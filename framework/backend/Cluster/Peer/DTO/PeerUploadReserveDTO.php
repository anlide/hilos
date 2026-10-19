<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;

/** Request receiver capacity before serializing raw upload bytes. */
final class PeerUploadReserveDTO extends PeerDTO
{
    public const string MESSAGE_TYPE = 'peer_upload_reserve';

    /**
     * @param string $reservationId Link-scoped identity
     * @param int $bytes Raw byte count
     * @throws PeerTransportException When identity or byte count is invalid
     */
    public function __construct(
        public readonly string $reservationId,
        public readonly int $bytes,
    ) {
        if ($reservationId === '' || $bytes <= 0) {
            throw new PeerTransportException('Invalid upload reserve identity or byte count');
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
            'bytes' => $this->bytes,
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
                self::requireInt($data, 'bytes'),
            );
        } catch (InvalidFormatException $e) {
            throw new PeerTransportException('Malformed upload reserve: ' . $e->getMessage());
        }
    }
}
