<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Actions\Item\VoucherActions;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * Voucher - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectVoucher>
 * @method __construct(ObjectVoucher $objectVoucher)
 *
 * @property-read ?int $id
 * @property-read string $code
 * @property-read int $discountPercent
 * @property-read bool $active
 * @property-read VoucherActions $actions Item-level write operations
 */
final class Voucher extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, code, discountPercent, active, actions, object)
     * @return int|string|bool|VoucherActions|ObjectVoucher|null Voucher field, actions or backing object
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): int|string|bool|VoucherActions|ObjectVoucher|null
    {
        return match ($name) {
            ObjectVoucher::id => $this->_object->id,
            ObjectVoucher::code => $this->_object->code,
            ObjectVoucher::discountPercent => $this->_object->discountPercent,
            ObjectVoucher::active => $this->_object->active,
            default => parent::__get($name),
        };
    }
}
