<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Cleanup of one product a test created, found by its name. */
final class ProductTestDeleteCommandData extends BaseDTO implements SignalDataInterface
{
    /** Reserved name of a product created by an e2e test. */
    private const string FIXTURE_NAME_PATTERN = '/\Ae2e product [0-9a-fA-F]{12}\z/';

    /**
     * @param string $name Name of the product fixture to remove
     * @throws InvalidFormatException When the name does not match the fixture format
     */
    public function __construct(public readonly string $name)
    {
        if (preg_match(self::FIXTURE_NAME_PATTERN, $name) !== 1) {
            throw new InvalidFormatException('Product cleanup needs a name in the form e2e product <12 hex digits>');
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
     * @throws InvalidFormatException When the name is absent, mistyped or outside the fixture format
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, EcommerceShopCommandConstants::name));
    }
}
