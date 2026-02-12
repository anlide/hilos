<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;

/**
 * Table data source backed by raw SQL.
 *
 * Structure only: no SQL execution implemented yet.
 * When implemented: constructor would accept connection + query builder or safe query descriptor.
 */
abstract class SqlTableDataSource implements TableDataSourceInterface
{
    public function getType(): TableType
    {
        return TableType::Sql;
    }

    public function supportsSnapshot(): bool
    {
        return false;
    }

    public function loadFull(): array
    {
        return [];
    }

    public function loadPage(int $offset, int $limit): array
    {
        return [];
    }

    public function getTotalCount(): int
    {
        return -1;
    }
}
