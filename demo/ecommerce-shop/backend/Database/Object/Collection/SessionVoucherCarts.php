<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\SessionVoucherCarts as EntitySessionVoucherCarts;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart as EntitySessionVoucherCart;
use Demo\EcommerceShop\Database\Object\Item\SessionVoucherCart as ObjectSessionVoucherCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * Voucher bindings keyed by browser session ID.
 *
 * @extends Objects<ObjectSessionVoucherCart>
 * @method ObjectSessionVoucherCart|null first()
 * @method ObjectSessionVoucherCart|null last()
 * @method ObjectSessionVoucherCart|null get(int|string $key)
 * @method ObjectSessionVoucherCart|null offsetGet(mixed $offset)
 */
final class SessionVoucherCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectSessionVoucherCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySessionVoucherCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::sessionVoucherCarts;

    /**
     * Reconciles just this slot before the cart owner decides whether to write.
     *
     * @param int $sessionId Session that holds the voucher slot
     * @return ?ObjectSessionVoucherCart Persisted binding, or null for an empty slot
     * @throws DatabaseException When the keyed read or reconciliation fails
     * @throws InvalidArgumentException When the entity query is invalid
     * @throws SourceChangeSubscriberException When a subscriber to lost membership fails
     */
    public function loadStoredSlot(int $sessionId): ?ObjectSessionVoucherCart
    {
        $stored = EntitySessionVoucherCart::getById($sessionId);
        $held = $this->objects[$sessionId] ?? null;
        if ($stored === null) {
            if ($held !== null) {
                unset($this[$sessionId]);
            }

            return null;
        }

        if ($held !== null) {
            $held->acceptStored($stored);

            return $held;
        }

        $binding = ObjectSessionVoucherCart::fromEntity($stored);
        $this->hydrate($sessionId, $binding);

        return $binding;
    }
}
