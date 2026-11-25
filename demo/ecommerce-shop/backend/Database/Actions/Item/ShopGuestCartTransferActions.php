<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Item;

use Demo\EcommerceShop\Database\Object\Item\ShopGuestCartTransfer as ObjectShopGuestCartTransfer;
use Demo\EcommerceShop\Database\View\Item\ShopGuestCartTransfer;
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
 * @extends DbActions<ShopGuestCartTransfer, ObjectShopGuestCartTransfer>
 * @property-read ObjectShopGuestCartTransfer $object
 */
final class ShopGuestCartTransferActions extends DbActions
{
    /**
     * Saves receipt of the source in the same transaction as the recipient's cart writes.
     *
     * @throws ItemNotFoundForUpdateException When the transfer is no longer persisted
     * @throws WriteNotAllowedException When this process may not update the transfer
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws DatabaseException When the update fails
     * @throws ObjectCollectionNullException When the backing collection is missing
     * @throws ObjectGetIdStringNotImplementedException When the transfer has no usable key
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws InvalidArgumentException When the DB sync signal cannot be queued
     */
    public function markReceived(): void
    {
        if (!$this->object->isRelated()) {
            throw new ItemNotFoundForUpdateException('Guest cart transfer is no longer persisted');
        }

        $this->ensureCanWrite();
        $this->object->received = true;
        $this->object->sync();
    }

    /**
     * Gives a later choice precedence over the voucher of a locked pending transfer.
     *
     * @throws ItemNotFoundForUpdateException When the transfer is no longer persisted
     * @throws WriteNotAllowedException When this process may not update the transfer
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws DatabaseException When the update fails
     * @throws ObjectCollectionNullException When the backing collection is missing
     * @throws ObjectGetIdStringNotImplementedException When the transfer has no usable key
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws InvalidArgumentException When the DB sync signal cannot be queued
     */
    public function markVoucherSuperseded(): void
    {
        if (!$this->object->isRelated()) {
            throw new ItemNotFoundForUpdateException('Guest cart transfer is no longer persisted');
        }

        $this->ensureCanWrite();
        $this->object->voucherSuperseded = true;
        $this->object->sync();
    }

    /**
     * Removes the transfer and drops its cached membership. A removed transfer can be removed again.
     *
     * @throws WriteNotAllowedException When this process may not remove the transfer
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws DatabaseException When the collection binding or delete fails
     * @throws ObjectCollectionNullException When the backing collection is missing
     * @throws ObjectGetIdStringNotImplementedException When the transfer has no usable key
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
            ?? throw new ObjectCollectionNullException('Guest cart transfer removal requires its owning collection');
        $key = $this->object->getIdString();
        $this->object->delete();
        unset($collection[$key]);
    }
}
