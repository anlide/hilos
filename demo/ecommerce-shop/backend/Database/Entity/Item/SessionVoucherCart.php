<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\SessionVoucherCarts as EntitySessionVoucherCarts;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * The voucher selected for the cart of one browser session.
 *
 * @method static EntitySessionVoucherCarts get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySessionVoucherCarts getAll()
 */
final class SessionVoucherCart extends Entity
{
    public const string session_id = 'session_id';
    public const string voucher_id = 'voucher_id';

    public const string _table = 'session_voucher_cart';
    public const string _primary = self::session_id;
    public const array _columns = [self::session_id, self::voucher_id];
    public const array _types = [
        self::session_id => PhpType::INTEGER->value,
        self::voucher_id => PhpType::INTEGER->value,
    ];
    public const array _foreign = [
        self::voucher_id => Voucher::_table,
    ];
    public const array _indexes = [
        'idx_session_voucher_cart_voucher' => [Entity::INDEX_COLUMNS => [self::voucher_id]],
    ];

    public const string _setVia = self::session_id;
    public const bool _setRoot = false;

    // An anonymized restore purges the browser sessions and their bindings.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public int $session_id;
    public int $voucher_id;
}
