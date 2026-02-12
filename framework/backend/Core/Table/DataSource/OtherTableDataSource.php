<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;

/**
 * Table data source for "other" type (e.g. list of backups corresponding to real files).
 *
 * Abstract: project implements loadFull/loadPage/getTotalCount and optionally supportsSnapshot.
 */
abstract class OtherTableDataSource implements TableDataSourceInterface
{
    public function getType(): TableType
    {
        return TableType::Other;
    }

    abstract public function supportsSnapshot(): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    abstract public function loadFull(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    abstract public function loadPage(int $offset, int $limit): array;

    abstract public function getTotalCount(): int;
}
