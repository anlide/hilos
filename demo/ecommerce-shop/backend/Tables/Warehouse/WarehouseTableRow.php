<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Warehouse;

use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the warehouses table: one warehouse and what it keeps.
 *
 * {@see productCount} and {@see unitCount} are counted from the warehouse's stock rows, not stored.
 */
final class WarehouseTableRow extends AbstractTableRow
{
    public const string id = ObjectWarehouse::id;
    public const string name = ObjectWarehouse::name;
    public const string address = ObjectWarehouse::address;
    public const string productCount = 'productCount';
    public const string unitCount = 'unitCount';

    /**
     * @param int $id Persisted warehouse id
     * @param string $name Warehouse name
     * @param string $address Warehouse address
     * @param int $productCount Different products the warehouse keeps
     * @param int $unitCount Units of those products in all
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $address,
        public int $productCount,
        public int $unitCount,
    ) {
    }

    /**
     * @return int Warehouse id, the stable row key of the warehouses table
     */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * @return array<string, int|string> Row payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
            self::address => $this->address,
            self::productCount => $this->productCount,
            self::unitCount => $this->unitCount,
        ];
    }

    /**
     * Builds a warehouse table row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Restored row
     * @throws InvalidFormatException When the payload is missing a field the row is rendered by
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: self::requireInt($data, self::id),
            name: self::requireString($data, self::name),
            address: self::requireString($data, self::address),
            productCount: self::requireInt($data, self::productCount),
            unitCount: self::requireInt($data, self::unitCount),
        );
    }
}
