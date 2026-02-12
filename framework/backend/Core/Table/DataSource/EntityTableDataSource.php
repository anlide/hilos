<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;
use Hilos\Database\Idea\IdeaCollection;

/**
 * Table data source backed by an IdeaCollection (Entity type).
 *
 * Demo: Idea::$table->register(Idea::users, new EntityTableDataSource(Idea::$db->users)).
 */
class EntityTableDataSource implements TableDataSourceInterface
{
    public function __construct(
        private readonly IdeaCollection $collection,
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
