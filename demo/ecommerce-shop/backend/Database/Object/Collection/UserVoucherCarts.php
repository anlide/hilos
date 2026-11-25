<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\UserVoucherCarts as EntityUserVoucherCarts;
use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart as EntityUserVoucherCart;
use Demo\EcommerceShop\Database\Object\Item\UserVoucherCart as ObjectUserVoucherCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;

/**
 * Voucher bindings keyed by person ID.
 *
 * @extends Objects<ObjectUserVoucherCart>
 * @method ObjectUserVoucherCart|null first()
 * @method ObjectUserVoucherCart|null last()
 * @method ObjectUserVoucherCart|null get(int|string $key)
 * @method ObjectUserVoucherCart|null offsetGet(mixed $offset)
 */
final class UserVoucherCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectUserVoucherCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserVoucherCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::userVoucherCarts;

    /**
     * @param int $userId Cart owner
     * @return ?int Selected voucher ID, or null for an empty slot
     * @throws DatabaseException When the saved row cannot be read
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function storedVoucherId(int $userId): ?int
    {
        return EntityUserVoucherCart::getById($userId)?->voucher_id;
    }

    /**
     * Reads the owner's slot inside PRIMARY and reconciles the object used for writes.
     *
     * The indexed agent already owns this set before opening PRIMARY. An indexed
     * snapshot read sees the preceding owner's commit and avoids a gap lock on an empty slot.
     *
     * @param int $userId Cart owner
     * @return ?ObjectUserVoucherCart Saved binding with its write baseline, or null for an empty slot
     * @throws HilosException When the transaction, stored row or mirror cannot be read or reconciled
     */
    public function loadStoredSlot(int $userId): ?ObjectUserVoucherCart
    {
        if (Database::runtimeTransactionLevel(EntityUserVoucherCart::getDatabase()->connectionIndex) === null) {
            throw new DatabaseException('The account voucher slot read requires a PRIMARY transaction.');
        }

        $stored = EntityUserVoucherCart::getById($userId);
        $held = $this->objects[$userId] ?? null;
        if ($stored === null) {
            if ($held !== null) {
                unset($this[$userId]);
            }

            return null;
        }
        if ($held !== null) {
            $held->acceptStored($stored);

            return $held;
        }

        $binding = ObjectUserVoucherCart::fromEntity($stored);
        $this->hydrate($userId, $binding);

        return $binding;
    }
}
