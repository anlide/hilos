<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Closure;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Object\Collection\SessionProductCarts as ObjectSessionProductCarts;
use Demo\EcommerceShop\Database\Object\Collection\ShopGuestCartTransfers as ObjectShopGuestCartTransfers;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Demo\EcommerceShop\Database\View\Collection\SessionProductCarts as DbCollectionSessionProductCarts;
use Demo\EcommerceShop\Database\View\Item\SessionProductCart;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Throwable;

/**
 * SessionProductCartsActions - write operations for the carts of visitors without an account.
 *
 * Called by the guest carts library alone, which holds every row. A row is one line of the cart
 * of one browser session: a product and how many units of it, one at least. What a line becomes
 * is decided by {@see CartLineRules}, the rules the cart of an account shares; every check comes
 * before any write, so a refused call leaves the cart as it was, and a call that changes nothing
 * writes nothing and asks for no right. Mutations of an active cart hold its session lock through commit.
 * The cart never writes the stock.
 *
 * @extends DbActions<SessionProductCart, ObjectSessionProductCarts>
 * @property-read DbCollectionSessionProductCarts $collection
 * @property-read ObjectSessionProductCarts $objectCollection
 */
final class SessionProductCartsActions extends DbActions
{
    /**
     * Puts one unit of a product into the cart of a session.
     *
     * @param int $sessionId Number of the browser session whose cart it is
     * @param int $cartEpoch Number of the cart being changed
     * @param int $productId Id of the product
     * @param ?string $token Current connection token when adding on its behalf
     * @throws ValidationException When the product does not exist, a unique piece is not in a warehouse yet, or a
     *     stock item has no unit left for one more
     * @throws CreateNotAllowedException When this process may not add a line
     * @throws WriteNotAllowedException When this process may not change a line
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     * @throws Throwable When the cart transaction cannot complete
     */
    public function add(int $sessionId, int $cartEpoch, int $productId, ?string $token = null): void
    {
        self::inPrimaryTransaction(function () use ($sessionId, $cartEpoch, $productId, $token): void {
            $this->requireActiveCart($sessionId, $cartEpoch, $token);
            $line = $this->objectCollection->lockProductLine($sessionId, $cartEpoch, $productId);
            $quantity = CartLineRules::added($productId, $line?->quantity);
            if ($line === null) {
                $this->addLine($sessionId, $cartEpoch, $productId, $quantity);
                return;
            }
            $this->changeQuantity($line, $quantity);
        });
    }

    /**
     * Steps the quantity of a stock item in the cart of a session one unit up or down.
     *
     * @param int $sessionId Number of the browser session whose cart it is
     * @param int $cartEpoch Number of the cart being changed
     * @param int $productId Id of the stock item
     * @param int $step {@see CartLineRules::STEP_UP} or {@see CartLineRules::STEP_DOWN}
     * @throws InvalidArgumentException When the step is neither one unit up nor one down, or a loaded object does not
     *     match its collection
     * @throws ValidationException When the product does not exist, is a unique piece or is not in the cart, the step
     *     down would leave no unit, or the step up would go above the stock
     * @throws WriteNotAllowedException When this process may not change a line
     * @throws LogicException When collection class constants are not configured
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     * @throws Throwable When the cart transaction cannot complete
     */
    public function step(int $sessionId, int $cartEpoch, int $productId, int $step): void
    {
        self::inPrimaryTransaction(function () use ($sessionId, $cartEpoch, $productId, $step): void {
            $this->requireActiveCart($sessionId, $cartEpoch, null);
            $line = $this->objectCollection->lockProductLine($sessionId, $cartEpoch, $productId);
            $quantity = CartLineRules::stepped($productId, $line?->quantity, $step);
            if ($line !== null) {
                $this->changeQuantity($line, $quantity);
            }
        });
    }

    /**
     * Sets the quantity of a stock item in the cart of a session to an exact number.
     *
     * @param int $sessionId Number of the browser session whose cart it is
     * @param int $cartEpoch Number of the cart being changed
     * @param int $productId Id of the stock item
     * @param int $quantity Number of units the line is to hold
     * @throws ValidationException When the product does not exist, is a unique piece or is not in the cart, the number
     *     is below one unit, or it is above the stock
     * @throws WriteNotAllowedException When this process may not change a line
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     * @throws Throwable When the cart transaction cannot complete
     */
    public function setQuantity(int $sessionId, int $cartEpoch, int $productId, int $quantity): void
    {
        self::inPrimaryTransaction(function () use ($sessionId, $cartEpoch, $productId, $quantity): void {
            $this->requireActiveCart($sessionId, $cartEpoch, null);
            $line = $this->objectCollection->lockProductLine($sessionId, $cartEpoch, $productId);
            $exact = CartLineRules::exact($productId, $line?->quantity, $quantity);
            if ($line !== null) {
                $this->changeQuantity($line, $exact);
            }
        });
    }

    /**
     * Takes a product out of the cart of a session; a product not in it is left as it is.
     *
     * @param int $sessionId Number of the browser session whose cart it is
     * @param int $cartEpoch Number of the cart being changed
     * @param int $productId Id of the product
     * @throws WriteNotAllowedException When this process may not remove a line
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection or DB sync cannot be queued
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the delete
     * @throws PropertyNotFoundException When the line does not expose its actions property
     * @throws ActionsClassException When the line's actions cannot be resolved
     * @throws ObjectCollectionNullException When the line has no owning collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the delete
     * @throws Throwable When the cart transaction cannot complete
     */
    public function remove(int $sessionId, int $cartEpoch, int $productId): void
    {
        self::inPrimaryTransaction(function () use ($sessionId, $cartEpoch, $productId): void {
            $this->requireActiveCart($sessionId, $cartEpoch, null);
            $line = $this->objectCollection->lockProductLine($sessionId, $cartEpoch, $productId);
            if ($line !== null) {
                $this->collection[$line->id]->actions->remove();
            }
        });
    }

    /**
     * Runs a product cart write at the caller's PRIMARY boundary, or opens that boundary.
     *
     * @template T
     * @param Closure(): T $operation Cart operation holding its storage locks
     * @return T Result produced at the transaction boundary
     * @throws Throwable When the transaction or its operation fails
     */
    public static function inPrimaryTransaction(Closure $operation): mixed
    {
        $level = Database::runtimeTransactionLevel(DatabaseConnectionDefaults::PRIMARY_INDEX);
        if ($level !== null && $level->testScope === null) {
            return $operation();
        }

        return Database::transaction(DatabaseNames::PRIMARY, $operation);
    }

    /**
     * Sweeps the inactive, unassigned pairs of the sessions named by the session library.
     *
     * @param list<int> $sessionIds Swept session IDs
     * @throws HilosException When the candidates cannot be read or removed
     * @throws Throwable When an orphan pair's transaction fails
     */
    public function sweepForSessions(array $sessionIds): void
    {
        $this->sweepOrphanPairs(
            $this->objectCollection->storedLineIdsByPair($sessionIds),
            Hilos::$db->sessionVoucherCarts->storedSlotIdsByPair($sessionIds),
        );
    }

    /**
     * Sweeps only the physical rows chosen by the bounded recovery window.
     *
     * @param list<int> $lineIds Persisted orphan line candidates
     * @param list<int> $bindingIds Persisted orphan voucher candidates
     * @throws HilosException When the candidates cannot be read or removed
     * @throws Throwable When an orphan pair's transaction fails
     */
    public function sweepRecoveryRows(array $lineIds, array $bindingIds): void
    {
        $lines = [];
        foreach ($lineIds as $lineId) {
            $coordinate = $this->objectCollection->storedLineCoordinate($lineId);
            if ($coordinate !== null) {
                [$sessionId, $cartEpoch] = $coordinate;
                $lines[$sessionId][$cartEpoch][] = $lineId;
            }
        }

        $bindings = [];
        foreach ($bindingIds as $bindingId) {
            $coordinate = Hilos::$db->sessionVoucherCarts->storedSlotCoordinate($bindingId);
            if ($coordinate !== null) {
                [$sessionId, $cartEpoch] = $coordinate;
                $bindings[$sessionId][$cartEpoch] = $bindingId;
            }
        }

        $this->sweepOrphanPairs($lines, $bindings);
    }

    /**
     * Clears a portion of an inactive source pair while its saved receipt is locked.
     *
     * @param int $sessionId Source session (which may have been swept)
     * @param int $cartEpoch Number of the assigned guest cart
     * @param int $transferId Saved transfer that authorizes clearing the pair
     * @param int $userId Recipient named in that transfer
     * @param int $limit Remaining source-row budget
     * @param Closure(): void $cleared Announcement to release when the entire pair is empty
     * @return int Number of source rows locked, including when the receipt no longer authorizes deletion
     * @throws HilosException When storage or the ORM write guard refuses the operation
     * @throws Throwable When the transaction or its announcement fails
     */
    public function clearReceivedCart(int $sessionId, int $cartEpoch, int $transferId, int $userId, int $limit, Closure $cleared): int
    {
        return self::inPrimaryTransaction(function () use ($sessionId, $cartEpoch, $transferId, $userId, $limit, $cleared): int {
            if (Hilos::$db->sessions->lockCartEpoch($sessionId) === $cartEpoch) {
                return 0;
            }
            $lineIds = $this->objectCollection->lockCartLineIds($sessionId, $cartEpoch, $limit);
            $count = count($lineIds);
            $binding = $count < $limit ? Hilos::$db->sessionVoucherCarts->storedSlot($sessionId, $cartEpoch) : null;
            if ($binding !== null) {
                ++$count;
            }
            if (!ObjectShopGuestCartTransfers::lockReceivedTransfer($transferId, $userId, $sessionId, $cartEpoch)) {
                return $count;
            }

            foreach ($lineIds as $lineId) {
                $this->collection[$lineId]->actions->remove();
            }
            $binding?->actions->remove();
            // A full portion leaves emptiness to a new attempt with the same durable receipt.
            if ($count < $limit) {
                Database::afterCommit($cleared, DatabaseConnectionDefaults::PRIMARY_INDEX);
            }

            return $count;
        });
    }

    /**
     * Commits a pair before acquiring another session lock. On a transfer miss,
     * InnoDB can retain a gap lock that also covers another session's insertion.
     *
     * @param array<int, array<int, list<int>>> $lineIds Physical line candidates by session and epoch
     * @param array<int, array<int, int>> $bindingIds Physical binding candidates by session and epoch
     * @throws HilosException When the pair cannot be checked or removed
     * @throws Throwable When an orphan pair's transaction fails
     */
    private function sweepOrphanPairs(array $lineIds, array $bindingIds): void
    {
        foreach (array_keys($lineIds + $bindingIds) as $sessionId) {
            $sessionLines = $lineIds[$sessionId] ?? [];
            $sessionBindings = $bindingIds[$sessionId] ?? [];
            foreach (array_keys($sessionLines + $sessionBindings) as $cartEpoch) {
                Database::transaction(
                    DatabaseNames::PRIMARY,
                    fn () => $this->sweepOrphanPair($sessionId, $cartEpoch, $sessionLines[$cartEpoch] ?? [], $sessionBindings[$cartEpoch] ?? null),
                );
            }
        }
    }

    /**
     * Rechecks both source families before locking the transfer key for this pair.
     *
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Epoch of the cart to sweep
     * @param list<int> $lineIds Physical line candidates
     * @param ?int $bindingId Physical binding candidate, if selected
     * @throws HilosException When a storage check or removal fails
     */
    private function sweepOrphanPair(int $sessionId, int $cartEpoch, array $lineIds, ?int $bindingId): void
    {
        if (Hilos::$db->sessions->lockCartEpoch($sessionId) === $cartEpoch) {
            return;
        }

        $lockedIds = [];
        foreach ($lineIds as $lineId) {
            if ($this->objectCollection->lockCartLine($sessionId, $cartEpoch, $lineId) !== null) {
                $lockedIds[] = $lineId;
            }
        }
        $binding = $bindingId === null ? null : Hilos::$db->sessionVoucherCarts->storedSlot($sessionId, $cartEpoch, $bindingId);
        if (($lockedIds === [] && $binding === null) || $this->objectCollection->hasLockedTransfer($sessionId, $cartEpoch)) {
            return;
        }

        foreach ($lockedIds as $lineId) {
            $this->collection[$lineId]->actions->remove();
        }
        $binding?->actions->remove();
    }

    /**
     * @param int $sessionId Session primary key
     * @param int $cartEpoch Expected active cart epoch
     * @param ?string $token Current connection token, when supplied by the caller
     * @throws ValidationException When storage no longer authorizes the addressed cart
     * @throws HilosException When the locking read fails
     */
    private function requireActiveCart(int $sessionId, int $cartEpoch, ?string $token): void
    {
        if (Hilos::$db->sessions->lockActiveGuestCartEpoch($sessionId, $token) !== $cartEpoch) {
            throw new ValidationException(CartAddProductSignalData::CART_NOT_READY);
        }
    }

    /**
     * Adds the line of a product to the cart of a session that holds none of it yet.
     *
     * @param int $sessionId Number of the browser session whose cart it is
     * @param int $cartEpoch Number of the cart
     * @param int $productId Id of the product
     * @param int $quantity Units the line holds, one at least
     * @throws CreateNotAllowedException When this process may not add a line
     * @throws DatabaseException When the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    private function addLine(int $sessionId, int $cartEpoch, int $productId, int $quantity): void
    {
        $this->ensureCanCreate();

        $line = ObjectSessionProductCart::create();
        $line->sessionId = $sessionId;
        $line->cartEpoch = $cartEpoch;
        $line->productId = $productId;
        $line->quantity = $quantity;
        $line->sync();
        $this->addObjectToCollection($line);
    }

    /**
     * Writes a new quantity onto a line; the quantity it already holds writes nothing.
     *
     * @param ObjectSessionProductCart $line The line
     * @param int $quantity Units the line holds from now on
     * @throws WriteNotAllowedException When this process may not change a line
     * @throws DatabaseException When the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    private function changeQuantity(ObjectSessionProductCart $line, int $quantity): void
    {
        if ($line->quantity === $quantity) {
            return;
        }
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $line->quantity = $quantity;
        $line->sync();
    }
}
