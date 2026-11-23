<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** A new warehouse for a unique piece and the placement the administrator saw. */
final class ProductWarehousePlaceActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];
    public const string expectedWarehouseId = 'expectedWarehouseId';

    /**
     * @param int $productId Id of the unique piece
     * @param int $warehouseId Id of the destination warehouse
     * @param ?int $expectedWarehouseId Id of the current warehouse, or null for an unplaced piece
     * @throws InvalidFormatException When a supplied id is not positive
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly ?int $expectedWarehouseId,
    ) {
        if ($productId <= 0 || $warehouseId <= 0 || ($expectedWarehouseId !== null && $expectedWarehouseId <= 0)) {
            throw new InvalidFormatException('Product and warehouse IDs must be positive integers.');
        }
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Placement with an explicit expected warehouse
     * @throws InvalidFormatException When the payload lacks a required field or carries an invalid id
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The warehouse placement action needs an object payload.');
        }
        if (!array_key_exists(self::expectedWarehouseId, $inner)) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . self::expectedWarehouseId);
        }

        return new static(
            self::requireInt($inner, ProductWarehouseStock::productId),
            self::requireInt($inner, ProductWarehouseStock::warehouseId),
            self::optionalInt($inner, self::expectedWarehouseId),
        );
    }

    /** @return string Warehouse placement action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::PRODUCT_WAREHOUSE_PLACE;
    }

    /** @return array{productId: int, warehouseId: int, expectedWarehouseId: ?int} Placement payload */
    public function toArray(): array
    {
        return [
            ProductWarehouseStock::productId => $this->productId,
            ProductWarehouseStock::warehouseId => $this->warehouseId,
            self::expectedWarehouseId => $this->expectedWarehouseId,
        ];
    }
}
