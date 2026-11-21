<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Shop connections agent → the person's agent: tell every tab and device of the person the cart. Not answered. */
final class AccountCartSendSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';

    /**
     * @param int $userId Person whose cart is told, and the agent index
     */
    public function __construct(
        public readonly int $userId,
    ) {
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Cart send request
     * @throws InvalidFormatException When the user id is absent or not an integer
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::userId));
    }

    /** @return array<string, int> Transport payload */
    public function toArray(): array
    {
        return [self::userId => $this->userId];
    }
}
