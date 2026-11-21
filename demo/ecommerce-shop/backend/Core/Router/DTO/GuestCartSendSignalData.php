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

    /**
     * @param int $sessionId Row of the session whose cart is told
     */
    public function __construct(
        public readonly int $sessionId,
    ) {
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Cart send request
     * @throws InvalidFormatException When the session id is absent or not an integer
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
