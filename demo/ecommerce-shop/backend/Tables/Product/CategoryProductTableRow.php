<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Product;

use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Storefront product row shared by all viewers.
 *
 * The picture URL points to the tile copy. Units and availability come from the product's
 * stock rows.
 */
final class CategoryProductTableRow extends AbstractTableRow
{
    public const string id = ObjectProduct::id;
    public const string slug = ObjectProduct::slug;
    public const string name = ObjectProduct::name;
    public const string pictureUrl = 'pictureUrl';
    public const string pictureAlt = 'pictureAlt';
    public const string kind = ObjectProduct::kind;
    public const string price = ObjectProduct::price;
    public const string units = 'units';
    public const string availability = 'availability';

    /**
     * @param int $id Persisted product id
     * @param string $slug Address segment of the product page
     * @param string $name Product name
     * @param string $pictureUrl Address of the tile copy of the product's picture
     * @param string $pictureAlt Text describing a unique piece's picture, empty for stock products
     * @param string $kind Kind of the product, a ProductKind value
     * @param int $price Price in kopecks
     * @param int $units Total units across warehouses, one for a placed unique piece
     * @param string $availability How much there is to sell, a ProductAvailability value
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $pictureUrl,
        public string $pictureAlt,
        public string $kind,
        public int $price,
        public int $units,
        public string $availability,
    ) {
    }

    /**
     * @return string Payload key that carries the product id
     */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * Builds a storefront product row from its table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Restored row
     * @throws InvalidFormatException When a required field is missing or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: self::requireInt($data, self::id),
            slug: self::requireString($data, self::slug),
            name: self::requireString($data, self::name),
            pictureUrl: self::requireString($data, self::pictureUrl),
            pictureAlt: self::requireString($data, self::pictureAlt),
            kind: self::requireString($data, self::kind),
            price: self::requireInt($data, self::price),
            units: self::requireInt($data, self::units),
            availability: self::requireString($data, self::availability),
        );
    }

    /**
     * @return int Product id, the stable row key of the storefront table
     */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /**
     * @return array<string, int|string> Storefront row payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::slug => $this->slug,
            self::name => $this->name,
            self::pictureUrl => $this->pictureUrl,
            self::pictureAlt => $this->pictureAlt,
            self::kind => $this->kind,
            self::price => $this->price,
            self::units => $this->units,
            self::availability => $this->availability,
        ];
    }
}
