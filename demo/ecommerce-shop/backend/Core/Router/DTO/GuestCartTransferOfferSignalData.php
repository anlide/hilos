<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Offers a saved guest cart transfer to its assigned person. */
final class GuestCartTransferOfferSignalData extends BaseDTO implements SignalDataInterface
{
    public const string transferId = 'transferId';
    public const string userId = 'userId';

    /**
     * @param int $transferId Saved transfer ID
     * @param int $userId Assigned recipient and agent index
     * @throws InvalidFormatException When either ID is not positive
     */
    public function __construct(
        public readonly int $transferId,
        public readonly int $userId,
    ) {
        if ($transferId <= 0 || $userId <= 0) {
            throw new InvalidFormatException('A guest cart transfer offer requires positive transfer and user IDs.');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Transfer offer
     * @throws InvalidFormatException When either ID is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::transferId), self::requireInt($data, self::userId));
    }

    /** @return array{transferId: int, userId: int} Transport payload */
    public function toArray(): array
    {
        return [self::transferId => $this->transferId, self::userId => $this->userId];
    }
}
