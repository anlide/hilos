<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;

/** Receiver responsibility for one previously reserved raw portion. */
final class PeerUploadAcceptedDTO extends PeerDTO
{
    public const string MESSAGE_TYPE = 'peer_upload_accepted';

    /**
     * @param string $receiptId Link-scoped identity
     * @throws PeerTransportException When identity or byte count is invalid
     */
    public function __construct(
        public readonly string $receiptId,
    ) {
        if ($receiptId === '') {
            throw new PeerTransportException('Invalid upload accepted identity or byte count');
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
            'receiptId' => $this->receiptId,
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
                self::requireString($data, 'receiptId'),
            );
        } catch (InvalidFormatException $e) {
            throw new PeerTransportException('Malformed upload accepted: ' . $e->getMessage());
        }
    }
}
