<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Actions\WarehouseFields;
use Demo\EcommerceShop\Database\Object\Collection\Warehouses as ObjectWarehouses;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Demo\EcommerceShop\Database\View\Collection\Warehouses as DbCollectionWarehouses;
use Demo\EcommerceShop\Database\View\Item\Warehouse;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\HilosException;

/**
 * WarehousesActions - write operations for the Warehouses collection.
 *
 * @extends DbActions<Warehouse, ObjectWarehouses>
 * @property-read DbCollectionWarehouses $collection
 * @property-read ObjectWarehouses $objectCollection
 */
final class WarehousesActions extends DbActions
{
    /**
     * Creates a warehouse and adds it to the collection.
     *
     * A name already taken is refused by the UNIQUE index rather than by a read before the
     * write: two administrators fit between such a read and its insert.
     *
     * @param string $name Warehouse name, unique across the shop
     * @param string $address Address of the premises
     * @return Warehouse Created warehouse Db item
     * @throws EmptyValueException When the name or the address is empty
     * @throws ValueTooLongException When the name or the address is longer than its column
     * @throws DuplicateValueException When another warehouse already has this name
     * @throws CreateNotAllowedException When this process may not add a warehouse
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading or the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function create(string $name, string $address): Warehouse
    {
        $this->ensureCanCreate();

        $warehouse = ObjectWarehouse::create();
        $warehouse->name = WarehouseFields::name($name);
        $warehouse->address = WarehouseFields::address($address);
        try {
            $warehouse->sync();
        } catch (DuplicateEntryException) {
            throw new DuplicateValueException('A warehouse with this name already exists');
        }

        $this->addObjectToCollection($warehouse);

        return $this->createDbItemFromObject($warehouse);
    }
}
