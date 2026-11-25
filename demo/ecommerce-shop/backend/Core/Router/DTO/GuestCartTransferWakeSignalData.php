<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Prompts the guest carts library to read the saved transfers of a session. */
final class GuestCartTransferWakeSignalData extends BaseDTO implements SignalDataInterface
{
    public const string sessionId = 'sessionId';

    /**
     * @param int $sessionId Session whose transfers are due for processing
     * @throws InvalidFormatException When the session ID is not positive
     */
    public function __construct(
        public readonly int $sessionId,
    ) {
        if ($sessionId <= 0) {
            throw new InvalidFormatException('A guest cart transfer wake requires a positive session ID.');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Wake frame
     * @throws InvalidFormatException When the session ID is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::sessionId));
    }

    /** @return array<string, int> Transport payload */
    public function toArray(): array
    {
        return [self::sessionId => $this->sessionId];
    }
}
