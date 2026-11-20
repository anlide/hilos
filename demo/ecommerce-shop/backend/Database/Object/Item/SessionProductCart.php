<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart as EntitySessionProductCart;
use Demo\EcommerceShop\Database\Object\Collection\SessionProductCarts as ObjectSessionProductCarts;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * SessionProductCart - Object wrapper for session_product_cart entity.
 *
 * @extends Object_<EntitySessionProductCart>
 *
 * @property-read ?int $id
 * @property int $sessionId
 * @property int $productId
 * @property int $quantity
 */
final class SessionProductCart extends Object_
{
    public const string ENTITY_CLASS = EntitySessionProductCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessionProductCarts::class;

    public const string id = 'id';
    public const string sessionId = 'sessionId';
    public const string productId = 'productId';
    public const string quantity = 'quantity';

    /**
     * Returns the value of a cart line object property by name.
     *
     * @param string $property Property name (id, sessionId, productId, quantity)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::sessionId => $this->entity->session_id,
            self::productId => $this->entity->product_id,
            self::quantity => $this->entity->quantity,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a cart line object property.
     *
     * @param string $property Property name to set (sessionId, productId, quantity)
     * @param mixed $value New value
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::sessionId => $this->entity->session_id = (int)$value,
            self::productId => $this->entity->product_id = (int)$value,
            self::quantity => $this->entity->quantity = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the cart line object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::sessionId => $this->entity->session_id,
            self::productId => $this->entity->product_id,
            self::quantity => $this->entity->quantity,
        ];
    }
}
