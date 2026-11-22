<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Cleanup of one category a test created, found by its name. */
final class CategoryDeleteCommandData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $name Name of the category to remove
     * @throws InvalidFormatException When the name is empty
     */
    public function __construct(public readonly string $name)
    {
        if ($name === '') {
            throw new InvalidFormatException('Deleting a category needs its name');
        }
    }

    /** @return array{name: string} Command payload */
    public function toArray(): array
    {
        return [EcommerceShopCommandConstants::name => $this->name];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Strictly parsed cleanup request
     * @throws InvalidFormatException When the name is absent, mistyped or empty
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, EcommerceShopCommandConstants::name));
    }
}
