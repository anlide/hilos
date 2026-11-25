<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\PhpType;

/**
 * The account assigned to receive one guest cart.
 *
 * The source session and its cart may disappear before the transfer record is removed.
 */
final class ShopGuestCartTransfer extends Entity
{
    public const string id = 'id';
    public const string session_id = 'session_id';
    public const string cart_epoch = 'cart_epoch';
    public const string user_id = 'user_id';
    public const string received = 'received';
    public const string voucher_superseded = 'voucher_superseded';

    public const string _table = 'shop_guest_cart_transfer';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::session_id, self::cart_epoch, self::user_id, self::received, self::voucher_superseded];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::session_id => PhpType::INTEGER->value,
        self::cart_epoch => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::received => PhpType::BOOLEAN->value,
        self::voucher_superseded => PhpType::BOOLEAN->value,
    ];

    public const array _foreign = [
        self::user_id => User::_table,
    ];

    public const array _indexes = [
        'uk_shop_guest_cart_transfer' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::session_id, self::cart_epoch]],
        'idx_shop_guest_cart_transfer_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $session_id;
    public int $cart_epoch;
    public int $user_id;
    public bool $received;
    public bool $voucher_superseded;
}
