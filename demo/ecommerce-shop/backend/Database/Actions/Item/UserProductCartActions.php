<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Item;

use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Demo\EcommerceShop\Database\View\Item\UserProductCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * @extends DbActions<UserProductCart, ObjectUserProductCart>
 * @property-read ObjectUserProductCart $object
 */
final class UserProductCartActions extends DbActions
{
    /**
     * Removes this saved line and drops its cached membership.
     *
     * @throws WriteNotAllowedException When this process may not remove the line from its owner's set
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws DatabaseException When the collection binding or delete fails
     * @throws ObjectCollectionNullException When the backing collection is missing
     * @throws ObjectGetIdStringNotImplementedException When the line has no usable key
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws InvalidArgumentException When the DB sync signal cannot be queued
     */
    public function remove(): void
    {
        $line = $this->object;
        if (!$line->isRelated()) {
            return;
        }

        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $collection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Cart line removal requires its owning collection');
        $key = $line->getIdString();
        $line->delete();
        unset($collection[$key]);
    }

    /**
     * Steps the saved quantity without changing the line's identity.
     *
     * @param int $step One unit up or down
     * @throws ValidationException When the line is gone or the product or stock rules refuse the step
     * @throws InvalidArgumentException When the direction, a stock object or its sync signal is invalid
     * @throws LogicException When the stock collection classes are not configured
     * @throws DatabaseException When checking the stock or saving the line fails
     * @throws ObjectGetIdStringNotImplementedException When a cart or stock row cannot provide its key
     * @throws CollectionNotManualException When a matched stock row cannot join the selection
     * @throws ObjectCollectionNullException When the line has no backing collection
     * @throws WriteNotAllowedException When this process cannot update the line
     * @throws CreateNotAllowedException When the write guard refuses a new row
     * @throws SourceChangeSubscriberException When a DB change subscriber fails
     * @throws HilosException When the catalog product cannot be read
     */
    public function step(int $step): void
    {
        if (!$this->object->isRelated()) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        $quantity = CartLineRules::stepped(
            $this->object->productId,
            $this->object->quantity,
            $step,
            readStoredStock: true,
        );
        $this->ensureCanWrite();
        $this->object->quantity = $quantity;
        $this->object->sync();
    }

    /**
     * Sets the quantity of this saved line from the quantity the editor saw.
     *
     * @param int $expectedQuantity Saved quantity the edit started from
     * @param string $quantity Original quantity text from the form
     * @throws ValidationException When the line is gone, the edit is stale or the quantity is refused
     * @throws InvalidArgumentException When a stock object or its sync signal is invalid
     * @throws LogicException When the stock collection classes are not configured
     * @throws DatabaseException When checking the stock or saving the line fails
     * @throws ObjectGetIdStringNotImplementedException When a cart or stock row cannot provide its key
     * @throws CollectionNotManualException When a matched stock row cannot join the selection
     * @throws ObjectCollectionNullException When the line has no backing collection
     * @throws WriteNotAllowedException When this process cannot update the line
     * @throws CreateNotAllowedException When the write guard refuses a new row
     * @throws SourceChangeSubscriberException When a DB change subscriber fails
     * @throws HilosException When the catalog product cannot be read
     */
    public function setQuantityFrom(int $expectedQuantity, string $quantity): void
    {
        if (!$this->object->isRelated()) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        $wanted = CartLineRules::exactFrom($this->object->productId, $this->object->quantity, $expectedQuantity, $quantity);
        if ($wanted === $this->object->quantity) {
            return;
        }

        $this->ensureCanWrite();
        $this->object->quantity = $wanted;
        $this->object->sync();
    }
}
