<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Shop connections agent → guest carts library: tell every tab of one session its cart. Not answered. */
final class GuestCartSendSignalData extends BaseDTO implements SignalDataInterface
{
    public const string sessionId = 'sessionId';
    public const string cartEpoch = 'cartEpoch';

    /**
     * @param int $sessionId Row of the session whose cart is told
     * @param int $cartEpoch Number of the addressed cart
     * @throws InvalidFormatException When the session ID or cart epoch is out of range
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly int $cartEpoch,
    ) {
        if ($sessionId <= 0 || $cartEpoch < 0) {
            throw new InvalidFormatException('A guest cart requires a positive session ID and a nonnegative epoch.');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Cart send request
     * @throws InvalidFormatException When the session ID or cart epoch is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::sessionId),
            self::requireInt($data, self::cartEpoch),
        );
    }

    /** @return array<string, int> Transport payload */
    public function toArray(): array
    {
        return [self::sessionId => $this->sessionId, self::cartEpoch => $this->cartEpoch];
    }
}
