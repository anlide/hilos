<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\Voucher as EntityVoucher;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * Vouchers - entity collection for Voucher entities.
 *
 * @extends EntityCollection<EntityVoucher>
 * @implements IteratorAggregate<int|string, EntityVoucher>
 * @implements ArrayAccess<int|string, EntityVoucher>
 */
final class Vouchers extends EntityCollection
{
    public const string ENTITY_CLASS = EntityVoucher::class;
}
