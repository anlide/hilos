<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;
use Hilos\Hilos\Database\DbCollection;

/**
 * Table data source backed by a DbCollection (Entity type).
 *
 * Example: Hilos::$table->register(Hilos::users, new EntityTableDataSource(Hilos::$db->users)).
 */
class EntityTableDataSource implements TableDataSourceInterface
{
    public function __construct(
        private readonly DbCollection $collection,
    ) {
    }

    public function getType(): TableType
    {
        return TableType::Entity;
    }

    public function supportsSnapshot(): bool
    {
        return true;
    }

    public function loadFull(): array
    {
        $rows = $this->collection->toArray(withId: true, idAsIndex: false, withBridges: false, withCalculation: false, toFrontend: true);
        return is_array($rows) ? $rows : [];
    }

    public function loadPage(int $offset, int $limit): array
    {
        $all = $this->loadFull();
        return array_slice($all, $offset, $limit);
    }

    public function getTotalCount(): int
    {
        return count($this->collection);
    }
}
