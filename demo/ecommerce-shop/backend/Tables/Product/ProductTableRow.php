<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Product;

use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the products table: one product of the shop's catalog and what the warehouses keep of it.
 *
 * {@see pictureUrl} is the address of the small copy of the product's picture, {@see categoryName}
 * the name of its category, and {@see units}, {@see warehouseName} and {@see availability} are
 * read from the product's stock rows, not stored. {@see changedAt} draws no column: it orders the
 * table and places the window's anchors.
 */
final class ProductTableRow extends AbstractTableRow
{
    public const string id = ObjectProduct::id;
    public const string name = ObjectProduct::name;
    public const string pictureUrl = 'pictureUrl';
    public const string categoryId = ObjectProduct::categoryId;
    public const string categoryName = 'categoryName';
    public const string kind = ObjectProduct::kind;
    public const string price = ObjectProduct::price;
    public const string units = 'units';
    public const string warehouseName = 'warehouseName';
    public const string availability = 'availability';
    public const string changedAt = ObjectProduct::changedAt;

    /**
     * @param int $id Persisted product id
     * @param string $name Product name
     * @param string $pictureUrl Address of the small copy of the product's picture
     * @param int $categoryId Id of the product's category
     * @param string $categoryName Name of the product's category
     * @param string $kind Kind of the product, a ProductKind value
     * @param int $price Price in kopecks
     * @param int $units Stock item: its units in all warehouses; unique piece: 1 in a warehouse, 0 not placed
     * @param ?string $warehouseName Warehouse keeping the unique piece; null for a stock item and a piece not placed
     * @param string $availability How much there is to sell, a ProductAvailability value
     * @param int $changedAt Moment of the product's last edit, epoch milliseconds
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $pictureUrl,
        public int $categoryId,
        public string $categoryName,
        public string $kind,
        public int $price,
        public int $units,
        public ?string $warehouseName,
        public string $availability,
        public int $changedAt,
    ) {
    }

    /**
     * @return int Product id, the stable row key of the products table
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
     * @return array<string, int|string|null> Row payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
            self::pictureUrl => $this->pictureUrl,
            self::categoryId => $this->categoryId,
            self::categoryName => $this->categoryName,
            self::kind => $this->kind,
            self::price => $this->price,
            self::units => $this->units,
            self::warehouseName => $this->warehouseName,
            self::availability => $this->availability,
            self::changedAt => $this->changedAt,
        ];
    }

    /**
     * Builds a product table row from raw table payload.
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
            pictureUrl: self::requireString($data, self::pictureUrl),
            categoryId: self::requireInt($data, self::categoryId),
            categoryName: self::requireString($data, self::categoryName),
            kind: self::requireString($data, self::kind),
            price: self::requireInt($data, self::price),
            units: self::requireInt($data, self::units),
            warehouseName: self::optionalString($data, self::warehouseName),
            availability: self::requireString($data, self::availability),
            changedAt: self::requireInt($data, self::changedAt),
        );
    }
}
