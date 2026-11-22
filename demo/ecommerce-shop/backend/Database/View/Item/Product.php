<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\Item\ProductActions;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;
use JsonException;

/**
 * Product - Db item with high-level abstraction and lazy loading.
 *
 * `kind` is read as a {@see ProductKind}: the column is written only from a case of it, so a
 * value outside the enum would be a corrupted table rather than a product to show.
 *
 * @extends DbItem<ObjectProduct>
 * @method __construct(ObjectProduct $objectProduct)
 *
 * @property-read ?int $id
 * @property-read string $name
 * @property-read int $categoryId
 * @property-read ProductKind $kind
 * @property-read int $price Price in kopecks
 * @property-read string $description
 * @property-read int $pictureFileId
 * @property-read string $slug
 * @property-read int $changedAt Moment of the last edit, epoch milliseconds
 * @property-read ProductActions $actions Item-level write operations
 */
final class Product extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, name, categoryId, kind, price, description, pictureFileId, slug, changedAt, actions)
     * @return int|string|ProductKind|ProductActions|null Property value or actions
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): int|string|ProductKind|ProductActions|null
    {
        return match ($name) {
            ObjectProduct::id => $this->_object->id,
            ObjectProduct::name => $this->_object->name,
            ObjectProduct::categoryId => $this->_object->categoryId,
            ObjectProduct::kind => ProductKind::from($this->_object->kind),
            ObjectProduct::price => $this->_object->price,
            ObjectProduct::description => $this->_object->description,
            ObjectProduct::pictureFileId => $this->_object->pictureFileId,
            ObjectProduct::slug => $this->_object->slug,
            ObjectProduct::changedAt => $this->_object->changedAt,
            default => parent::__get($name),
        };
    }

    /**
     * Identifies the saved catalog fields the editor reads.
     *
     * @return string Opaque version for comparing a submitted edit with the current product
     * @throws DatabaseException When the persisted fields cannot be read
     * @throws JsonException When the saved fields cannot be encoded
     */
    public function editVersion(): string
    {
        return hash('sha256', json_encode([
            $this->_object->id,
            $this->_object->name,
            $this->_object->categoryId,
            ProductKind::from($this->_object->kind)->value,
            $this->_object->price,
            $this->_object->description,
            $this->_object->pictureFileId,
            $this->_object->changedAt,
        ], JSON_THROW_ON_ERROR));
    }
}
