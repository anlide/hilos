<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart as EntitySessionVoucherCart;
use Demo\EcommerceShop\Database\Object\Collection\SessionVoucherCarts as ObjectSessionVoucherCarts;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Item\Object_;

/**
 * @extends Object_<EntitySessionVoucherCart>
 * @property-read ?int $id
 * @property int $sessionId
 * @property int $cartEpoch
 * @property int $voucherId
 */
final class SessionVoucherCart extends Object_
{
    public const string ENTITY_CLASS = EntitySessionVoucherCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessionVoucherCarts::class;

    public const string id = 'id';
    public const string sessionId = 'sessionId';
    public const string cartEpoch = 'cartEpoch';
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
            self::id => $this->entity->id,
            self::sessionId => $this->entity->session_id,
            self::cartEpoch => $this->entity->cart_epoch,
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
            self::sessionId => $this->entity->session_id = (int)$value,
            self::cartEpoch => $this->entity->cart_epoch = (int)$value,
            self::voucherId => $this->entity->voucher_id = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Refreshes the held row in place so its item actions use the persisted baseline.
     *
     * @param EntitySessionVoucherCart $stored Binding read from PRIMARY
     * @throws DatabaseException When the backing database cannot be resolved
     */
    public function acceptStored(EntitySessionVoucherCart $stored): void
    {
        $before = $this->entitySync->toArray();
        $after = $stored->toArray();
        if ($before === $after) {
            return;
        }

        Database::onRollback(
            fn () => $this->restoreSaved($before, related: true),
            index: $stored::getDatabase()->connectionIndex,
        );
        $this->restoreSaved($after, related: true);
    }

    /** @return array<string, int|null> Binding key, cart coordinate and voucher key */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::sessionId => $this->entity->session_id,
            self::cartEpoch => $this->entity->cart_epoch,
            self::voucherId => $this->entity->voucher_id,
        ];
    }
}
