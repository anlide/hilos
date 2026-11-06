<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\MigrationClaim;

/** Physical schema and table, independent of ORM names and prefixes. */
final readonly class TableName
{
    /**
     * @param string $database Resolved physical schema
     * @param string $table Exact base object name
     */
    public function __construct(public string $database, public string $table)
    {
    }

    /**
     * @return bool Whether this name belongs to migration initialization and is excluded from cleanup
     */
    public function isBootstrap(): bool
    {
        return in_array($this->table, [
            MigrationHistory::TABLE, MigrationClaim::TABLE, AdminViewModeLatchTable::TABLE, DatabaseMarker::TABLE,
        ], true);
    }
}
