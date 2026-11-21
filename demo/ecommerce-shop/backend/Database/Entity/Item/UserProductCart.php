<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\UserProductCarts as EntityUserProductCarts;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\PhpType;

/**
 * UserProductCart - Entity representing user_product_cart table row.
 *
 * How many units of a product the cart of a person with an account holds. The cart is the
 * person's: a row names the person and holds one unit at least, and a product out of the cart
 * has no row.
 *
 * @method static EntityUserProductCarts get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUserProductCarts getAll()
 */
final class UserProductCart extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string product_id = 'product_id';
    public const string quantity = 'quantity';

    // Table meta information
    public const string _table = 'user_product_cart';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::product_id,
        self::quantity,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::product_id => PhpType::INTEGER->value,
        self::quantity => PhpType::INTEGER->value,
    ];

    // No cascade: the database refuses to remove a person whose cart still stands - the person's
    // agent erases it in its own part of the erasure - and a product a cart still holds.
    public const array _foreign = [
        self::user_id => User::_table,
        self::product_id => Product::_table,
    ];

    // Indexes
    public const array _indexes = [
        'uk_user_product_cart' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::user_id, self::product_id]],
        'idx_user_product_cart_product' => [Entity::INDEX_COLUMNS => [self::product_id]],
    ];

    // The cart of a person, so the row is in the person's set; a product is no set.
    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // A line says which product and how many units, not who: the person is anonymized by their
    // own verdict and stays.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::user_id,
        self::product_id,
        self::quantity,
    ];

    // Properties
    public ?int $id = null;
    public int $user_id;
    public int $product_id;
    public int $quantity;
}
