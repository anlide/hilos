<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\ShopGuestCartTransfer as EntityShopGuestCartTransfer;
use Demo\EcommerceShop\Database\Object\Collection\ShopGuestCartTransfers as ObjectShopGuestCartTransfers;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Item\Object_;

/**
 * ShopGuestCartTransfer - Object wrapper for shop_guest_cart_transfer entity.
 *
 * @extends Object_<EntityShopGuestCartTransfer>
 *
 * @property-read ?int $id
 * @property int $sessionId
 * @property int $cartEpoch
 * @property int $userId
 * @property bool $received
 */
final class ShopGuestCartTransfer extends Object_
{
    public const string ENTITY_CLASS = EntityShopGuestCartTransfer::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectShopGuestCartTransfers::class;

    public const string id = 'id';
    public const string sessionId = 'sessionId';
    public const string cartEpoch = 'cartEpoch';
    public const string userId = 'userId';
    public const string received = 'received';

    /**
     * Returns the value of a transfer object property by name.
     *
     * @param string $property Property name (id, sessionId, cartEpoch, userId, received)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
     * @throws PropertyNotAccessibleException When the property does not exist or is not accessible
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::sessionId => $this->entity->session_id,
            self::cartEpoch => $this->entity->cart_epoch,
            self::userId => $this->entity->user_id,
            self::received => $this->entity->received,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a transfer object property.
     *
     * @param string $property Property name to set (sessionId, cartEpoch, userId, received)
     * @param mixed $value New value
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::sessionId => $this->entity->session_id = (int)$value,
            self::cartEpoch => $this->entity->cart_epoch = (int)$value,
            self::userId => $this->entity->user_id = (int)$value,
            self::received => $this->entity->received = (bool)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Refreshes the held object and its baseline so existing View wrappers see the saved assignment.
     *
     * @param EntityShopGuestCartTransfer $stored Assignment read from PRIMARY
     * @throws DatabaseException When the backing database cannot be resolved
     */
    public function acceptStored(EntityShopGuestCartTransfer $stored): void
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

    /**
     * Converts the transfer object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::sessionId => $this->entity->session_id,
            self::cartEpoch => $this->entity->cart_epoch,
            self::userId => $this->entity->user_id,
            self::received => $this->entity->received,
        ];
    }
}
