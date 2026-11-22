<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\Vouchers as EntityVouchers;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Voucher - Entity representing voucher table row.
 *
 * A shop discount with a unique code, a whole percentage and an active flag.
 *
 * @method static EntityVouchers get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityVouchers getAll()
 */
final class Voucher extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string code = 'code';
    public const string discount_percent = 'discount_percent';
    public const string active = 'active';

    // Table meta information
    public const string _table = 'voucher';
    public const string _primary = self::id;
    public const bool _journaled = true;
    public const array _columns = [
        self::id,
        self::code,
        self::discount_percent,
        self::active,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::code => PhpType::STRING->value,
        self::discount_percent => PhpType::INTEGER->value,
        self::active => PhpType::BOOLEAN->value,
    ];

    // Indexes
    public const array _indexes = [
        'uk_voucher_code' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::code]],
    ];

    // Carts that refer to a voucher keep their own owners.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;

    // Shop discount codes and their settings are non-personal.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::code,
        self::discount_percent,
        self::active,
    ];

    // Properties
    public ?int $id = null;
    public string $code;
    public int $discount_percent;
    public bool $active = false;
}
