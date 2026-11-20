<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\SessionProductCarts as EntitySessionProductCarts;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * SessionProductCart - Entity representing session_product_cart table row.
 *
 * How many units of a product the cart of a visitor without an account holds. The cart is the
 * browser session's: a row names the session by its number and holds one unit at least, and a
 * product out of the cart has no row.
 *
 * @method static EntitySessionProductCarts get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySessionProductCarts getAll()
 */
final class SessionProductCart extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string session_id = 'session_id';
    public const string product_id = 'product_id';
    public const string quantity = 'quantity';

    // Table meta information
    public const string _table = 'session_product_cart';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::session_id,
        self::product_id,
        self::quantity,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::session_id => PhpType::INTEGER->value,
        self::product_id => PhpType::INTEGER->value,
        self::quantity => PhpType::INTEGER->value,
    ];

    // No cascade: the database refuses to remove a product a cart still holds. The session has no
    // foreign key: its rows go with the sweep of the session, by the guest carts library.
    public const array _foreign = [
        self::product_id => Product::_table,
    ];

    // Indexes
    public const array _indexes = [
        'uk_session_product_cart' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::session_id, self::product_id]],
        'idx_session_product_cart_product' => [Entity::INDEX_COLUMNS => [self::product_id]],
    ];

    // The cart of a session, so the row is in the session's set; the session is named softly, so
    // the set is the top of its own tree, and a product is no set.
    public const string _setVia = self::session_id;
    public const bool _setRoot = false;

    // The sessions a cart points at are purged by a restore that anonymizes, so a cart left
    // behind would name a browser nobody has.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    // Properties
    public ?int $id = null;
    public int $session_id;
    public int $product_id;
    public int $quantity;
}
