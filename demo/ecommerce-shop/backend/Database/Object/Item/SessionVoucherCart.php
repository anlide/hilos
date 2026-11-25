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
 * @property int $sessionId
 * @property int $voucherId
 */
final class SessionVoucherCart extends Object_
{
    public const string ENTITY_CLASS = EntitySessionVoucherCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessionVoucherCarts::class;

    public const string sessionId = 'sessionId';
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
            self::sessionId => $this->entity->session_id,
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
        if ($this->entitySync->voucher_id === $stored->voucher_id) {
            return;
        }

        $before = $this->entitySync->toArray();
        Database::onRollback(
            fn () => $this->restoreSaved($before, related: true),
            index: $stored::getDatabase()->connectionIndex,
        );
        $this->restoreSaved($stored->toArray(), related: true);
    }

    /** @return array<string, int> Cart owner and voucher keys */
    public function toArray(): array
    {
        return [
            self::sessionId => $this->entity->session_id,
            self::voucherId => $this->entity->voucher_id,
        ];
    }
}
