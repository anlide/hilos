<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart as EntityUserVoucherCart;
use Demo\EcommerceShop\Database\Object\Collection\UserVoucherCarts as ObjectUserVoucherCarts;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Item\Object_;

/**
 * @extends Object_<EntityUserVoucherCart>
 * @property int $userId
 * @property int $voucherId
 */
final class UserVoucherCart extends Object_
{
    public const string ENTITY_CLASS = EntityUserVoucherCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserVoucherCarts::class;

    public const string userId = 'userId';
    public const string voucherId = 'voucherId';

    /**
     * @param string $property Property name
     * @return mixed Property value or inherited getter result
     * @throws DatabaseException When the object is misconfigured
     * @throws PropertyNotAccessibleException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::userId => $this->entity->user_id,
            self::voucherId => $this->entity->voucher_id,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Property name
     * @param mixed $value New value
     * @throws DatabaseException When the property cannot be set
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::voucherId => $this->entity->voucher_id = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, int> Cart owner and voucher keys */
    public function toArray(): array
    {
        return [
            self::userId => $this->entity->user_id,
            self::voucherId => $this->entity->voucher_id,
        ];
    }
}
