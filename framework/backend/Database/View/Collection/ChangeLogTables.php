<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogOverview;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\ChangeLog\Section\ChangeLogTableDetail;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\ChangeLogTables as ObjectChangeLogTables;
use Hilos\Database\View\Item\ChangeLogTable;
use Hilos\HilosException;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogTable, ObjectChangeLogTables>
 * @method ObjectChangeLogTables|null getObjectCollection()
 * @method ChangeLogTable|null current()
 * @method ChangeLogTable|null first()
 * @method ChangeLogTable|null last()
 * @method ChangeLogTable offsetGet(mixed $offset)
 */
class ChangeLogTables extends DbCollection
{
    public const string DB_ITEM_CLASS = ChangeLogTable::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogTables::class;

    private const string OBJECT_COLLECTION_REQUIRED_MESSAGE = 'Journal section reads require an Object collection';

    /**
     * @return ChangeLogOverview Exact count, approximate bytes, oldest entry and live coverage
     * @throws HilosException When the collection, journal SQL or metadata cannot be read
     */
    public function sectionOverview(): ChangeLogOverview
    {
        return ($this->getObjectCollection()
            ?? throw new DatabaseException(self::OBJECT_COLLECTION_REQUIRED_MESSAGE))
            ->sectionOverview();
    }

    /**
     * @return ChangeLogReadCollection<ChangeLogTableSummary> Every live primary base table, ordered by name
     * @throws HilosException When the collection, journal SQL or metadata cannot be read
     */
    public function sectionTables(): ChangeLogReadCollection
    {
        return ($this->getObjectCollection()
            ?? throw new DatabaseException(self::OBJECT_COLLECTION_REQUIRED_MESSAGE))
            ->sectionTables();
    }

    /**
     * @return ChangeLogReadCollection<ChangeLogNoisyColumn> Dense live noise declarations, ordered by table and column
     * @throws HilosException When the collection, journal SQL or metadata cannot be read
     */
    public function sectionNoisyColumns(): ChangeLogReadCollection
    {
        return ($this->getObjectCollection()
            ?? throw new DatabaseException(self::OBJECT_COLLECTION_REQUIRED_MESSAGE))
            ->sectionNoisyColumns();
    }

    /**
     * @param string $name External table catalog key; offsets remain global row ids
     * @return ?ChangeLogTableDetail Live table detail, or null for an unknown name
     * @throws HilosException When the collection, journal SQL or metadata cannot be read
     */
    public function sectionTable(string $name): ?ChangeLogTableDetail
    {
        return ($this->getObjectCollection()
            ?? throw new DatabaseException(self::OBJECT_COLLECTION_REQUIRED_MESSAGE))
            ->sectionTable($name);
    }

    /**
     * @param string $name External table catalog key; offsets remain global row ids
     * @return ?int Fresh journal dictionary id, or null when absent
     * @throws HilosException When the collection, journal SQL or metadata cannot be read
     */
    public function sectionIdByName(string $name): ?int
    {
        return ($this->getObjectCollection()
            ?? throw new DatabaseException(self::OBJECT_COLLECTION_REQUIRED_MESSAGE))
            ->sectionIdByName($name);
    }
}
