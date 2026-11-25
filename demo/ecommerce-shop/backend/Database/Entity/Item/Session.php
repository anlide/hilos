<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Hilos\Database\Entity\Item\Session as FrameworkSession;
use Hilos\Database\PhpType;

/** A browser session with the number of its active guest product cart. */
final class Session extends FrameworkSession
{
    public const string cart_epoch = 'cart_epoch';

    public const array _columns = [...parent::_columns, self::cart_epoch];

    public const array _types = [
        ...parent::_types,
        self::cart_epoch => PhpType::INTEGER->value,
    ];

    public int $cart_epoch;
}
