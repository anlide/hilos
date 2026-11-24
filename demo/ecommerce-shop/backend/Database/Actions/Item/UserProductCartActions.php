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
}
