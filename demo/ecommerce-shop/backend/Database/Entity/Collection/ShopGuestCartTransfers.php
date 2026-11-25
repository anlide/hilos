<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use Demo\EcommerceShop\Database\Entity\Item\ShopGuestCartTransfer;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * Guest cart transfer rows as stored.
 *
 * @extends EntityCollection<ShopGuestCartTransfer>
 */
final class ShopGuestCartTransfers extends EntityCollection
{
    public const string ENTITY_CLASS = ShopGuestCartTransfer::class;
}
