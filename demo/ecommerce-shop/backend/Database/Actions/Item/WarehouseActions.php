<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Item;

use Demo\EcommerceShop\Database\Actions\WarehouseFields;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Demo\EcommerceShop\Database\View\Item\Warehouse;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\HilosException;

/**
 * WarehouseActions - write operations for a single Warehouse item.
 *
 * @extends DbActions<Warehouse, ObjectWarehouse>
 * @property-read ObjectWarehouse $object
 */
final class WarehouseActions extends DbActions
{
    /**
     * Renames the warehouse and moves it to another address.
     *
     * A refused edit leaves the object as it was: both fields are checked before either is
     * assigned, and the object rolls its own fields back when the save is refused.
     *
     * @param string $name New warehouse name, unique across the shop
     * @param string $address New address of the premises
     * @throws ItemNotFoundForUpdateException If the warehouse id is null (not persisted)
     * @throws EmptyValueException When the name or the address is empty
     * @throws ValueTooLongException When the name or the address is longer than its column
     * @throws DuplicateValueException When another warehouse already has this name
     * @throws WriteNotAllowedException When this process may not write the warehouse
     * @throws DatabaseException When the update fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws HilosException When the right, the database or the announcement refuses the save
     */
    public function update(string $name, string $address): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Warehouse not found for update (id is null)');
        }

        $name = WarehouseFields::name($name);
        $address = WarehouseFields::address($address);
        $this->object->name = $name;
        $this->object->address = $address;
        try {
            $this->object->sync();
        } catch (DuplicateEntryException) {
            throw new DuplicateValueException('A warehouse with this name already exists');
        }
    }

    /**
     * Deletes the warehouse.
     *
     * @throws ItemNotFoundForDeleteException If the warehouse id is null (not persisted)
     * @throws ObjectCollectionNullException If object collection is null
     * @throws WriteNotAllowedException When this process may not remove the warehouse
     * @throws DatabaseException When the delete fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException On database error
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Warehouse not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}
