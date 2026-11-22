<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Item;

use Demo\EcommerceShop\Database\Object\Item\UserVoucherCart as ObjectUserVoucherCart;
use Demo\EcommerceShop\Database\View\Item\UserVoucherCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * @extends DbActions<UserVoucherCart, ObjectUserVoucherCart>
 * @property-read ObjectUserVoucherCart $object
 */
final class UserVoucherCartActions extends DbActions
{
    /**
     * Keeps the binding as it is when the requested voucher is already selected.
     *
     * @param int $voucherId Voucher ID
     * @throws ItemNotFoundForUpdateException When this binding is no longer persisted
     * @throws WriteNotAllowedException When this process may not update the binding
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws DatabaseException When the binding or update fails
     * @throws ObjectCollectionNullException When the backing collection is missing
     * @throws ObjectGetIdStringNotImplementedException When the binding has no usable key
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws InvalidArgumentException When the DB sync signal cannot be queued
     */
    public function setVoucher(int $voucherId): void
    {
        if (!$this->object->isRelated()) {
            throw new ItemNotFoundForUpdateException('Cart voucher binding is no longer persisted');
        }

        $this->ensureCanWrite();
        if ($this->object->voucherId === $voucherId) {
            return;
        }

        $this->object->voucherId = $voucherId;
        $this->object->sync();
    }

    /**
     * Removes the binding and drops its cached membership. A removed binding can be removed again.
     *
     * @throws WriteNotAllowedException When this process may not remove the binding
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws DatabaseException When the binding or delete fails
     * @throws ObjectCollectionNullException When the backing collection is missing
     * @throws ObjectGetIdStringNotImplementedException When the binding has no usable key
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws InvalidArgumentException When the DB sync signal cannot be queued
     */
    public function remove(): void
    {
        if (!$this->object->isRelated()) {
            return;
        }

        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $collection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Cart voucher removal requires its owning collection');
        $key = $this->object->getIdString();
        $this->object->delete();
        unset($collection[$key]);
    }
}
