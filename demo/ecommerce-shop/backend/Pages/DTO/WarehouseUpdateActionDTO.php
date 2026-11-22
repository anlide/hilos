<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Edit request, applied only while the warehouse still holds the values the editor opened with;
 * the warehouse actions trim and check the raw name and address at the write boundary.
 */
final class WarehouseUpdateActionDTO extends ActionPayloadDTO
{
    public const string expectedName = 'expectedName';
    public const string expectedAddress = 'expectedAddress';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $id Warehouse primary ID
     * @param string $name Raw new warehouse name, checked by the warehouse actions
     * @param string $address Raw new warehouse address, checked by the warehouse actions
     * @param string $expectedName Name the editor opened with, without normalization
     * @param string $expectedAddress Address the editor opened with, without normalization
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $address,
        public readonly string $expectedName,
        public readonly string $expectedAddress,
    ) {
    }

    /** @return string Warehouse update action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::WAREHOUSE_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Warehouse id with the unmodified new and expected values
     * @throws InvalidFormatException When the data section, the id, a value or an expected value has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The warehouse action needs an object payload.');
        }
        if (isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            self::requireInt($inner, ObjectWarehouse::id),
            self::requireString($inner, ObjectWarehouse::name),
            self::requireString($inner, ObjectWarehouse::address),
            self::requireString($inner, self::expectedName),
            self::requireString($inner, self::expectedAddress),
        );
    }

    /** @return array<string, int|string> Edit payload without envelope metadata */
    public function toArray(): array
    {
        return [
            ObjectWarehouse::id => $this->id,
            ObjectWarehouse::name => $this->name,
            ObjectWarehouse::address => $this->address,
            self::expectedName => $this->expectedName,
            self::expectedAddress => $this->expectedAddress,
        ];
    }
}
