<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\UserVoucherCarts as EntityUserVoucherCarts;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\PhpType;

/**
 * The voucher selected for the cart of one person.
 *
 * @method static EntityUserVoucherCarts get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUserVoucherCarts getAll()
 */
final class UserVoucherCart extends Entity
{
    public const string user_id = 'user_id';
    public const string voucher_id = 'voucher_id';

    public const string _table = 'user_voucher_cart';
    public const string _primary = self::user_id;
    public const array _columns = [self::user_id, self::voucher_id];
    public const array _types = [
        self::user_id => PhpType::INTEGER->value,
        self::voucher_id => PhpType::INTEGER->value,
    ];
    public const array _foreign = [
        self::user_id => User::_table,
        self::voucher_id => Voucher::_table,
    ];
    public const array _indexes = [
        'idx_user_voucher_cart_voucher' => [Entity::INDEX_COLUMNS => [self::voucher_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // The internal account key survives anonymization and the voucher is a catalog entry.
    public const array _pii = [];

    public const array _piiNotPersonal = [self::user_id, self::voucher_id];

    public int $user_id;
    public int $voucher_id;
}
