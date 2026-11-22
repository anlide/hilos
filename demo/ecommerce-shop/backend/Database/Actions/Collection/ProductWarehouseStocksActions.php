<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Entity\Item\Warehouse;
use Demo\EcommerceShop\Database\Object\Collection\ProductWarehouseStocks as ObjectProductWarehouseStocks;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\View\Collection\ProductWarehouseStocks as DbCollectionProductWarehouseStocks;
use Demo\EcommerceShop\Database\View\Item\ProductWarehouseStock;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\HilosException;

/**
 * ProductWarehouseStocksActions - write operations for the stock of the shop's warehouses.
 *
 * Called by the stock library alone, which holds every row. A row says how many units of a
 * product a warehouse keeps, one at least: no row is no units. A stock item has a row in each
 * warehouse that keeps it; a unique piece is one row of one unit, in the warehouse it is kept
 * in. Every check comes before any write, so a refused call leaves nothing behind, and a call
 * that changes nothing writes nothing - neither a row nor a line of the journal. Each write is
 * one statement, so no transaction is opened.
 *
 * The warehouse is checked twice: against the warehouses read in memory first, and by the
 * database's reference last. A warehouse deleted after the first check refuses a row that names
 * it with the same words.
 *
 * @extends DbActions<ProductWarehouseStock, ObjectProductWarehouseStocks>
 * @property-read DbCollectionProductWarehouseStocks $collection
 * @property-read ObjectProductWarehouseStocks $objectCollection
 */
final class ProductWarehouseStocksActions extends DbActions
{
    /** Most units of one product one warehouse keeps. */
    public const int UNITS_MAX = 999_999;

    /**
     * Sets how many units of a stock item a warehouse keeps.
     *
     * Zero takes the row away, and a warehouse with no row and zero units is left as it is. The
     * same number the warehouse already keeps changes nothing.
     *
     * @param int $productId Id of the stock item
     * @param int $warehouseId Id of the warehouse
     * @param int $units Units the warehouse keeps from now on, zero for none
     * @throws ValidationException When the product does not exist or is a unique piece, the warehouse no longer
     *     exists, or the units are negative or above the ceiling
     * @throws CreateNotAllowedException When this process may not add a row
     * @throws WriteNotAllowedException When this process may not change or remove a row
     * @throws DatabaseException When the rows cannot be read, or the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function setUnits(int $productId, int $warehouseId, int $units): void
    {
        if ($this->checkedProductKind($productId) !== ProductKind::STOCK) {
            throw new ValidationException('A unique piece has no units to set: it is kept in one warehouse');
        }
        $this->checkWarehouseExists($warehouseId);
        if ($units < 0) {
            throw new ValidationException('Units cannot be negative');
        }
        if ($units > self::UNITS_MAX) {
            throw new ValidationException('Units cannot be more than 999,999');
        }

        $stock = null;
        foreach ($this->collection->whereColumnIs(ObjectProductWarehouseStock::productId, $productId) as $row) {
            if ($row->warehouseId === $warehouseId) {
                $stock = $row->getObject();
                break;
            }
        }

        if ($stock === null) {
            if ($units !== 0) {
                $this->addStock($productId, $warehouseId, $units);
            }
            return;
        }
        if ($units === 0) {
            $this->ensureCanWrite(TruthSourceOperation::Remove);
            $idString = $stock->getIdString();
            $stock->delete();
            unset($this->objectCollection[$idString]);
            return;
        }
        if ($stock->units === $units) {
            return;
        }
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $stock->units = $units;
        $stock->sync();
    }

    /**
     * Puts a unique piece into a warehouse, or moves it there from the one it is kept in.
     *
     * The piece keeps its one row: a move changes the warehouse of that row. A piece already in
     * this warehouse is left as it is.
     *
     * @param int $productId Id of the unique piece
     * @param int $warehouseId Id of the warehouse to keep it in
     * @throws ValidationException When the product does not exist or is a stock item, or the warehouse no longer exists
     * @throws CreateNotAllowedException When this process may not add a row
     * @throws WriteNotAllowedException When this process may not change a row
     * @throws DatabaseException When the rows cannot be read, or the database refuses the write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function place(int $productId, int $warehouseId): void
    {
        if ($this->checkedProductKind($productId) !== ProductKind::UNIQUE) {
            throw new ValidationException('A stock item is not moved: set its units in each warehouse');
        }
        $this->checkWarehouseExists($warehouseId);

        $piece = $this->collection->whereColumnIs(ObjectProductWarehouseStock::productId, $productId)->first();
        if ($piece === null) {
            // A unique piece is one unit.
            $this->addStock($productId, $warehouseId, 1);
            return;
        }
        if ($piece->warehouseId === $warehouseId) {
            return;
        }
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $stock = $piece->getObject();
        $stock->warehouseId = $warehouseId;
        $this->syncNamingWarehouse($stock);
    }

    /**
     * Adds the row of a product in a warehouse that keeps none of it yet.
     *
     * @param int $productId Id of the product
     * @param int $warehouseId Id of the warehouse
     * @param int $units Units the warehouse keeps, one at least
     * @throws ValidationException When the warehouse no longer exists in the database
     * @throws CreateNotAllowedException When this process may not add a row
     * @throws DatabaseException When the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    private function addStock(int $productId, int $warehouseId, int $units): void
    {
        $this->ensureCanCreate();

        $stock = ObjectProductWarehouseStock::create();
        $stock->productId = $productId;
        $stock->warehouseId = $warehouseId;
        $stock->units = $units;
        $this->syncNamingWarehouse($stock);
        $this->addObjectToCollection($stock);
    }

    /**
     * Saves a row that names its warehouse anew, the database judging whether the warehouse is still there.
     *
     * A refused save leaves the row as it was stored, and a refused new row is not stored at all.
     *
     * @param ObjectProductWarehouseStock $stock Row to insert, or row moved to another warehouse
     * @throws ValidationException When the database has no row of the warehouse the stock row names
     * @throws CreateNotAllowedException When this process may not add a row
     * @throws WriteNotAllowedException When this process may not change a row
     * @throws DatabaseException When the database refuses the save for another reason
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the announcement refuses the save
     */
    private function syncNamingWarehouse(ObjectProductWarehouseStock $stock): void
    {
        try {
            $stock->sync();
        } catch (ForeignKeyConstraintException $e) {
            if ($e->referencedTable() !== Warehouse::_table) {
                throw $e;
            }

            throw new ValidationException('This warehouse no longer exists', previous: $e);
        }
    }

    /**
     * Checks that a product exists and returns its kind; products are read by key.
     *
     * @param int $productId Id of the product
     * @return ProductKind Kind of the product
     * @throws ValidationException When there is no such product
     * @throws HilosException When the products cannot be read
     */
    private function checkedProductKind(int $productId): ProductKind
    {
        if (!isset(Hilos::$db->products[$productId])) {
            throw new ValidationException('This product does not exist');
        }

        return Hilos::$db->products[$productId]->kind;
    }

    /**
     * Checks that a warehouse still exists; the warehouses are read whole.
     *
     * @param int $warehouseId Id of the warehouse
     * @throws ValidationException When the warehouse has been deleted
     * @throws HilosException When the warehouses cannot be read
     */
    private function checkWarehouseExists(int $warehouseId): void
    {
        if (!isset(Hilos::$db->warehouses[$warehouseId])) {
            throw new ValidationException('This warehouse no longer exists');
        }
    }
}
