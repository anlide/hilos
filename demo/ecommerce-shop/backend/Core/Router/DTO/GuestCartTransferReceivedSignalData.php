<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Tells the guest carts library that the recipient committed the product lines. */
final class GuestCartTransferReceivedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string transferId = 'transferId';
    public const string userId = 'userId';

    /**
     * @param int $transferId Saved transfer ID
     * @param int $userId Assigned recipient
     * @throws InvalidFormatException When either ID is not positive
     */
    public function __construct(
        public readonly int $transferId,
        public readonly int $userId,
    ) {
        if ($transferId <= 0 || $userId <= 0) {
            throw new InvalidFormatException('A guest cart transfer requires positive transfer and user IDs.');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Transfer address
     * @throws InvalidFormatException When either ID is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::transferId), self::requireInt($data, self::userId));
    }

    /** @return array<string, int> Transport payload */
    public function toArray(): array
    {
        return [self::transferId => $this->transferId, self::userId => $this->userId];
    }
}
