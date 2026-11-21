<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Object\Collection\UserProductCarts as ObjectUserProductCarts;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Demo\EcommerceShop\Database\View\Collection\UserProductCarts as DbCollectionUserProductCarts;
use Demo\EcommerceShop\Database\View\Item\UserProductCart;
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
 * UserProductCartsActions - write operations for the carts of people with an account.
 *
 * Called by the person's agent alone, which owns the set of its person: the cart lines of that
 * person and no other. A row is one line of the cart: a product and how many units of it, one at
 * least. What a line becomes is decided by {@see CartLineRules}, the rules the cart of a guest
 * shares; every check comes before any write, so a refused call leaves the cart as it was, and a
 * call that changes nothing writes nothing and asks for no right. A new line is a creation in the
 * person's set, a new quantity an update of it and a line taken out a removal from it, so any
 * other process is refused before it writes. Each write is one statement, so no transaction is
 * opened. The cart never writes the stock.
 *
 * @extends DbActions<UserProductCart, ObjectUserProductCarts>
 * @property-read DbCollectionUserProductCarts $collection
 * @property-read ObjectUserProductCarts $objectCollection
 */
final class UserProductCartsActions extends DbActions
{
    /**
     * Puts one unit of a product into the cart of a person.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Id of the product
     * @throws ValidationException When the product does not exist, a unique piece is not in a warehouse yet, or a
     *     stock item has no unit left for one more
     * @throws CreateNotAllowedException When this process may not add a line to the person's cart
     * @throws WriteNotAllowedException When this process may not change a line of the person's cart
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function add(int $userId, int $productId): void
    {
        $line = $this->line($userId, $productId);
        $quantity = CartLineRules::added($productId, $line?->quantity);
        if ($line === null) {
            $this->addLine($userId, $productId, $quantity);
            return;
        }
        $this->changeQuantity($line, $quantity);
    }

    /**
     * Steps the quantity of a stock item in the cart of a person one unit up or down.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Id of the stock item
     * @param int $step {@see CartLineRules::STEP_UP} or {@see CartLineRules::STEP_DOWN}
     * @throws InvalidArgumentException When the step is neither one unit up nor one down, or a loaded object does not
     *     match its collection
     * @throws ValidationException When the product does not exist, is a unique piece or is not in the cart, the step
     *     down would leave no unit, or the step up would go above the stock
     * @throws WriteNotAllowedException When this process may not change a line of the person's cart
     * @throws LogicException When collection class constants are not configured
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function step(int $userId, int $productId, int $step): void
    {
        $line = $this->line($userId, $productId);
        $quantity = CartLineRules::stepped($productId, $line?->quantity, $step);
        if ($line !== null) {
            $this->changeQuantity($line, $quantity);
        }
    }

    /**
     * Sets the quantity of a stock item in the cart of a person to an exact number.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Id of the stock item
     * @param int $quantity Number of units the line is to hold
     * @throws ValidationException When the product does not exist, is a unique piece or is not in the cart, the number
     *     is below one unit, or it is above the stock
     * @throws WriteNotAllowedException When this process may not change a line of the person's cart
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function setQuantity(int $userId, int $productId, int $quantity): void
    {
        $line = $this->line($userId, $productId);
        $exact = CartLineRules::exact($productId, $line?->quantity, $quantity);
        if ($line !== null) {
            $this->changeQuantity($line, $exact);
        }
    }

    /**
     * Takes a product out of the cart of a person; a product not in it is left as it is.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Id of the product
     * @throws WriteNotAllowedException When this process may not remove a line of the person's cart
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the delete
     */
    public function remove(int $userId, int $productId): void
    {
        $line = $this->line($userId, $productId);
        if ($line === null) {
            return;
        }
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        $this->deleteLine($line);
    }

    /**
     * Removes every line of the cart of a person whose account is being erased.
     *
     * The lines are read from the database on demand; an empty cart removes nothing and asks for
     * no right.
     *
     * @param int $userId Person whose cart is erased
     * @return int Number of lines removed
     * @throws WriteNotAllowedException When this process may not remove a line of the person's cart
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the delete
     */
    public function deleteForUser(int $userId): int
    {
        $lines = [];
        foreach ($this->collection->whereColumnIs(ObjectUserProductCart::userId, $userId) as $line) {
            $lines[] = $line->getObject();
        }
        if ($lines === []) {
            return 0;
        }

        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        foreach ($lines as $line) {
            $this->deleteLine($line);
        }

        return count($lines);
    }

    /**
     * Finds the line of a product in the cart of a person.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Id of the product
     * @return ?ObjectUserProductCart The line, or null when the cart holds none of the product
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws DatabaseException When the rows cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    private function line(int $userId, int $productId): ?ObjectUserProductCart
    {
        foreach ($this->collection->whereColumnIs(ObjectUserProductCart::userId, $userId) as $row) {
            if ($row->productId === $productId) {
                return $row->getObject();
            }
        }

        return null;
    }

    /**
     * Adds the line of a product to the cart of a person that holds none of it yet.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Id of the product
     * @param int $quantity Units the line holds, one at least
     * @throws CreateNotAllowedException When this process may not add a line to the person's cart
     * @throws DatabaseException When the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    private function addLine(int $userId, int $productId, int $quantity): void
    {
        $this->ensureCanCreateInSet((string)$userId);

        $line = ObjectUserProductCart::create();
        $line->userId = $userId;
        $line->productId = $productId;
        $line->quantity = $quantity;
        $line->sync();
        $this->addObjectToCollection($line);
    }

    /**
     * Writes a new quantity onto a line; the quantity it already holds writes nothing.
     *
     * @param ObjectUserProductCart $line The line
     * @param int $quantity Units the line holds from now on
     * @throws WriteNotAllowedException When this process may not change a line of the person's cart
     * @throws DatabaseException When the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    private function changeQuantity(ObjectUserProductCart $line, int $quantity): void
    {
        if ($line->quantity === $quantity) {
            return;
        }
        $this->ensureCanWriteSet((string)$line->userId, TruthSourceOperation::Update);
        $line->quantity = $quantity;
        $line->sync();
    }

    /**
     * Deletes a line and lets the collection forget it.
     *
     * @param ObjectUserProductCart $line The line
     * @throws DatabaseException When the database refuses the delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the collection refuses the delete
     */
    private function deleteLine(ObjectUserProductCart $line): void
    {
        $idString = $line->getIdString();
        $line->delete();
        unset($this->objectCollection[$idString]);
    }
}
