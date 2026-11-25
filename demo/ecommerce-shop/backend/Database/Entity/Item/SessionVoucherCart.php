<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\SessionVoucherCarts as EntitySessionVoucherCarts;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * The voucher selected for one cart epoch of a browser session.
 *
 * @method static EntitySessionVoucherCarts get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySessionVoucherCarts getAll()
 */
final class SessionVoucherCart extends Entity
{
    public const string id = 'id';
    public const string session_id = 'session_id';
    public const string cart_epoch = 'cart_epoch';
    public const string voucher_id = 'voucher_id';

    public const string _table = 'session_voucher_cart';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::session_id, self::cart_epoch, self::voucher_id];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::session_id => PhpType::INTEGER->value,
        self::cart_epoch => PhpType::INTEGER->value,
        self::voucher_id => PhpType::INTEGER->value,
    ];
    public const array _foreign = [
        self::voucher_id => Voucher::_table,
    ];
    public const array _indexes = [
        'uk_session_voucher_cart' => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::session_id, self::cart_epoch],
        ],
        'idx_session_voucher_cart_voucher' => [Entity::INDEX_COLUMNS => [self::voucher_id]],
    ];

    public const string _setVia = self::session_id;
    public const bool _setRoot = false;

    // An anonymized restore purges the browser sessions and their bindings.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $session_id;
    public int $cart_epoch;
    public int $voucher_id;
}
