<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** One raw portion covered by an unused receiver window on its original link. */
final class UploadMemoryReservationData extends BaseDTO
{
    /**
     * @param string $reservationId Receiver window identity
     * @param string $receiptId Distinct sender portion identity
     * @param int $bytes Raw bytes covered by the window
     * @throws InvalidFormatException When an identity is empty or bytes are not positive
     */
    public function __construct(
        public readonly string $reservationId,
        public readonly string $receiptId,
        public readonly int $bytes,
    ) {
        if ($reservationId === '' || $receiptId === '' || $bytes <= 0) {
            throw new InvalidFormatException('Upload reservation needs identities and positive raw bytes');
        }
    }

    /** @return array{reservationId: string, receiptId: string, bytes: int} Peer envelope boundary */
    public function toArray(): array
    {
        return ['reservationId' => $this->reservationId, 'receiptId' => $this->receiptId, 'bytes' => $this->bytes];
    }

    /**
     * @param array<string, mixed> $data Peer envelope boundary
     * @return static Validated receiver window and portion
     * @throws InvalidFormatException When a required field is absent, mistyped or out of range
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, 'reservationId'),
            self::requireString($data, 'receiptId'),
            self::requireInt($data, 'bytes'),
        );
    }
}
