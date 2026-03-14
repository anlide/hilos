<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableType;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Table data source backed by a DbCollection (Entity type).
 *
 * Chooses between two strategies:
 * - In-memory: when all objects are already loaded — uses InMemoryTableFilter
 * - SQL: when the collection uses lazy loading — queries DB via Object/View pipeline
 *
 * Example: Hilos::$table->register(Hilos::users, new EntityTableDataSource(Hilos::$db->users)).
 */
readonly class EntityTableDataSource implements TableDataSourceInterface
{
    /**
     * Creates data source backed by DbCollection.
     *
     * @param DbCollection $collection Db collection (e.g. Hilos::$db->users)
     */
    public function __construct(
        private DbCollection $collection,
    ) {
    }

    /**
     * Returns the source type (Entity, Sql, or Other).
     *
     * @return TableType Source type enum value
     */
    public function getType(): TableType
    {
        return TableType::Entity;
    }

    /**
     * Executes a table query with search, sort and pagination.
     *
     * If all objects are already in memory, filters them in-place.
     * Otherwise queries DB through the Object → View pipeline,
     * which stores loaded objects in the common collection.
     *
     * @param TableQueryDTO $query Query parameters
     *
     * @return TableResultDTO Query result with rows and total count
     * @throws DatabaseException If query execution fails
     */
    public function query(TableQueryDTO $query): TableResultDTO
    {
        $objectCollection = $this->collection->getObjectCollection();

        if ($objectCollection === null || $objectCollection->isAllLoaded()) {
            $rows = $this->collection->toArray(idAsIndex: false, toFrontend: true);
            return InMemoryTableFilter::apply($rows, $query);
        }

        $result = $this->collection->queryPage($query);

        return new TableResultDTO(
            rows: $result[TableConstants::RESULT_KEY_ROWS],
            totalCount: $result[TableConstants::RESULT_KEY_TOTAL_COUNT],
            offset: $query->offset,
            limit: $query->limit,
        );
    }
}
