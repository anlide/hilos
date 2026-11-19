<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Category;

use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the categories table: one category of the shop and how many products it holds.
 *
 * {@see picture} is the address of the small copy of the category's picture, and {@see productCount}
 * is counted from the products, not stored.
 */
final class CategoryTableRow extends AbstractTableRow
{
    public const string id = ObjectCategory::id;
    public const string name = ObjectCategory::name;
    public const string description = ObjectCategory::description;
    public const string position = ObjectCategory::position;
    public const string picture = 'picture';
    public const string productCount = 'productCount';

    /**
     * @param int $id Persisted category id
     * @param string $name Category name
     * @param string $description Category description
     * @param int $position Place of the category in the catalog
     * @param string $picture Address of the small copy of the category's picture
     * @param int $productCount Products of either kind in the category
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public int $position,
        public string $picture,
        public int $productCount,
    ) {
    }

    /**
     * @return int Category id, the stable row key of the categories table
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
            self::description => $this->description,
            self::position => $this->position,
            self::picture => $this->picture,
            self::productCount => $this->productCount,
        ];
    }

    /**
     * Builds a category table row from raw table payload.
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
            description: self::requireString($data, self::description),
            position: self::requireInt($data, self::position),
            picture: self::requireString($data, self::picture),
            productCount: self::requireInt($data, self::productCount),
        );
    }
}
