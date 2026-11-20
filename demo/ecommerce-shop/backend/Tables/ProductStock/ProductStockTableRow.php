<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\ProductStock;

use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the stock table of one product: one warehouse and the units of the product it keeps.
 *
 * {@see units} is zero for a warehouse that keeps none of the product: no stock row stands behind it.
 */
final class ProductStockTableRow extends AbstractTableRow
{
    public const string warehouseId = ObjectProductWarehouseStock::warehouseId;
    public const string warehouseName = 'warehouseName';
    public const string units = ObjectProductWarehouseStock::units;

    /**
     * @param int $warehouseId Persisted warehouse id
     * @param string $warehouseName Warehouse name
     * @param int $units Units of the product the warehouse keeps, zero when it keeps none
     */
    public function __construct(
        public int $warehouseId,
        public string $warehouseName,
        public int $units,
    ) {
    }

    /**
     * @return int Warehouse id, the stable row key of the product stock table
     */
    public function getRowKey(): int
    {
        return $this->warehouseId;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::warehouseId;
    }

    /**
     * @return array<string, int|string> Row payload
     */
    public function toArray(): array
    {
        return [
            self::warehouseId => $this->warehouseId,
            self::warehouseName => $this->warehouseName,
            self::units => $this->units,
        ];
    }

    /**
     * Builds a product stock table row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Restored row
     * @throws InvalidFormatException When the payload is missing a field the row is rendered by
     */
    public static function fromArray(array $data): static
    {
        return new static(
            warehouseId: self::requireInt($data, self::warehouseId),
            warehouseName: self::requireString($data, self::warehouseName),
            units: self::requireInt($data, self::units),
        );
    }
}
