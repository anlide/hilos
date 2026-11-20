<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Object\Collection\SessionProductCarts as ObjectSessionProductCarts;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Demo\EcommerceShop\Database\View\Collection\SessionProductCarts as DbCollectionSessionProductCarts;
use Demo\EcommerceShop\Database\View\Item\SessionProductCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * SessionProductCartsActions - write operations for the carts of visitors without an account.
 *
 * Called by the guest carts library alone, which holds every row. A row is one line of the cart
 * of one browser session: a product and how many units of it, one at least. What a line becomes
 * is decided by {@see CartLineRules}, the rules the cart of an account shares; every check comes
 * before any write, so a refused call leaves the cart as it was, and a call that changes nothing
 * writes nothing and asks for no right. Each write is one statement, so no transaction is opened.
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
     * @param int $productId Id of the product
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
     */
    public function add(int $sessionId, int $productId): void
    {
        $line = $this->line($sessionId, $productId);
        $quantity = CartLineRules::added($productId, $line?->quantity);
        if ($line === null) {
            $this->addLine($sessionId, $productId, $quantity);
            return;
        }
        $this->changeQuantity($line, $quantity);
    }

    /**
     * Steps the quantity of a stock item in the cart of a session one unit up or down.
     *
     * @param int $sessionId Number of the browser session whose cart it is
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
     */
    public function step(int $sessionId, int $productId, int $step): void
    {
        $line = $this->line($sessionId, $productId);
        $quantity = CartLineRules::stepped($productId, $line?->quantity, $step);
        if ($line !== null) {
            $this->changeQuantity($line, $quantity);
        }
    }

    /**
     * Sets the quantity of a stock item in the cart of a session to an exact number.
     *
     * @param int $sessionId Number of the browser session whose cart it is
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
     */
    public function setQuantity(int $sessionId, int $productId, int $quantity): void
    {
        $line = $this->line($sessionId, $productId);
        $exact = CartLineRules::exact($productId, $line?->quantity, $quantity);
        if ($line !== null) {
            $this->changeQuantity($line, $exact);
        }
    }

    /**
     * Takes a product out of the cart of a session; a product not in it is left as it is.
     *
     * @param int $sessionId Number of the browser session whose cart it is
     * @param int $productId Id of the product
     * @throws WriteNotAllowedException When this process may not remove a line
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the delete
     */
    public function remove(int $sessionId, int $productId): void
    {
        $line = $this->line($sessionId, $productId);
        if ($line === null) {
            return;
        }
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->deleteLine($line);
    }

    /**
     * Removes every line of the carts of swept sessions.
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
            $lines[] = $line->getObject();
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
     * Finds the line of a product in the cart of a session.
     *
     * @param int $sessionId Number of the browser session whose cart it is
     * @param int $productId Id of the product
     * @return ?ObjectSessionProductCart The line, or null when the cart holds none of the product
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws DatabaseException When the rows cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    private function line(int $sessionId, int $productId): ?ObjectSessionProductCart
    {
        foreach ($this->collection->whereColumnIs(ObjectSessionProductCart::sessionId, $sessionId) as $row) {
            if ($row->productId === $productId) {
                return $row->getObject();
            }
        }

        return null;
    }

    /**
     * Adds the line of a product to the cart of a session that holds none of it yet.
     *
     * @param int $sessionId Number of the browser session whose cart it is
     * @param int $productId Id of the product
     * @param int $quantity Units the line holds, one at least
     * @throws CreateNotAllowedException When this process may not add a line
     * @throws DatabaseException When the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    private function addLine(int $sessionId, int $productId, int $quantity): void
    {
        $this->ensureCanCreate();

        $line = ObjectSessionProductCart::create();
        $line->sessionId = $sessionId;
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
