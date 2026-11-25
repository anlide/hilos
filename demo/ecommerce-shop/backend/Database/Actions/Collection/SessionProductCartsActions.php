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
     * Removes eligible lines of the carts of swept sessions.
     *
     * The library holds every row, so the lines are picked from memory; no line of those sessions
     * removes nothing and asks for no right.
     *
     * @param list<int> $sessionIds Numbers of the swept browser sessions
     * @return int Number of lines removed
     * @throws WriteNotAllowedException When this process may not remove a line
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the delete
     */
    public function deleteForSessions(array $sessionIds): int
    {
        $lines = [];
        foreach ($this->collection->whereColumnIn(ObjectSessionProductCart::sessionId, ...$sessionIds) as $line) {
            $orphan = $this->lockOrphanedLine($line->id, $line->sessionId, $line->cartEpoch);
            if ($orphan !== null) {
                $lines[] = $orphan;
            }
        }
        if ($lines === []) {
            return 0;
        }

        $this->ensureCanWrite(TruthSourceOperation::Remove);
        foreach ($lines as $line) {
            $this->deleteLine($line);
        }

        return count($lines);
    }

    /**
     * Removes a recovery batch by primary key without walking the mirror or a whole session's cart.
     *
     * @param list<int> $lineIds Persisted orphan line IDs selected for this recovery batch
     * @throws WriteNotAllowedException When this process may not remove a line
     * @throws ItemNotFoundException When a selected line does not resolve by key
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws DatabaseException When a row cannot be read or deleted
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws HilosException When the collection refuses the delete
     */
    public function deleteRecoveryLines(array $lineIds): void
    {
        if ($lineIds === []) {
            return;
        }

        foreach ($lineIds as $lineId) {
            $coordinate = $this->objectCollection->storedLineCoordinate($lineId);
            if ($coordinate === null) {
                continue;
            }
            if ($this->lockOrphanedLine($lineId, ...$coordinate) !== null) {
                $this->collection[$lineId]->actions->remove();
            }
        }
    }

    /**
     * Clears a portion of an inactive source pair while its saved receipt is locked.
     *
     * @param int $sessionId Source session (which may have been swept)
     * @param int $cartEpoch Number of the assigned guest cart
     * @param int $transferId Saved transfer that authorizes clearing the pair
     * @param int $userId Recipient named in that transfer
     * @param int $limit Remaining line budget
     * @param Closure(): void $cleared Announcement to release when the entire pair is empty
     * @return int Number of lines locked, including when the receipt no longer authorizes deletion
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
            if (!ObjectShopGuestCartTransfers::lockReceivedTransfer($transferId, $userId, $sessionId, $cartEpoch)) {
                return $count;
            }

            foreach ($lineIds as $lineId) {
                $this->collection[$lineId]->actions->remove();
            }
            // A full portion leaves emptiness to a new attempt with the same durable receipt.
            if ($count < $limit) {
                Database::afterCommit($cleared, DatabaseConnectionDefaults::PRIMARY_INDEX);
            }

            return $count;
        });
    }

    /**
     * @param int $lineId Primary key of the candidate
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Candidate's stored epoch
     * @return ?ObjectSessionProductCart The locked line if it still belongs to an inactive, unassigned pair
     * @throws HilosException When a storage check fails
     */
    private function lockOrphanedLine(int $lineId, int $sessionId, int $cartEpoch): ?ObjectSessionProductCart
    {
        if (Hilos::$db->sessions->lockCartEpoch($sessionId) === $cartEpoch) {
            return null;
        }

        $line = $this->objectCollection->lockCartLine($sessionId, $cartEpoch, $lineId);
        return $line !== null && !$this->objectCollection->hasLockedTransfer($sessionId, $cartEpoch) ? $line : null;
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

    /**
     * Deletes a line and lets the collection forget it.
     *
     * @param ObjectSessionProductCart $line The line
     * @throws DatabaseException When the database refuses the delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the collection refuses the delete
     */
    private function deleteLine(ObjectSessionProductCart $line): void
    {
        $idString = $line->getIdString();
        $line->delete();
        unset($this->objectCollection[$idString]);
    }
}
