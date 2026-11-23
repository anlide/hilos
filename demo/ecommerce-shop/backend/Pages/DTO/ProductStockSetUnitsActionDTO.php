<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\StockSetUnitsSignalData;
use Demo\EcommerceShop\Database\Actions\Collection\ProductWarehouseStocksActions;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** The browser's stock target, raw edit and the units the edit started from. */
final class ProductStockSetUnitsActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param int $productId Stock product being edited
     * @param int $warehouseId Warehouse whose units are being edited
     * @param string $units Raw units entered in the form
     * @param int $expectedUnits Units the editor opened with
     * @throws InvalidFormatException When an id or the expected count is out of range
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly string $units,
        public readonly int $expectedUnits,
    ) {
        if ($productId <= 0 || $warehouseId <= 0) {
            throw new InvalidFormatException('Select a product and warehouse to set units.');
        }
        if ($expectedUnits < 0 || $expectedUnits > ProductWarehouseStocksActions::UNITS_MAX) {
            throw new InvalidFormatException('Review the current units before setting stock.');
        }
    }

    /** @return string Set stock action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::PRODUCT_STOCK_SET_UNITS;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Stock edit with the original units text
     * @throws InvalidFormatException When a required field is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The stock action needs an object payload.');
        }

        return new static(
            self::requireInt($inner, ProductWarehouseStock::productId),
            self::requireInt($inner, ProductWarehouseStock::warehouseId),
            self::requireString($inner, ProductWarehouseStock::units),
            self::requireInt($inner, StockSetUnitsSignalData::expectedUnits),
        );
    }

    /** @return array<string, int|string> Stock edit without envelope metadata */
    public function toArray(): array
    {
        return [
            ProductWarehouseStock::productId => $this->productId,
            ProductWarehouseStock::warehouseId => $this->warehouseId,
            ProductWarehouseStock::units => $this->units,
            StockSetUnitsSignalData::expectedUnits => $this->expectedUnits,
        ];
    }
}
