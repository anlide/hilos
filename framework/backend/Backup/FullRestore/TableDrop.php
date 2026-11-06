<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** A planner-proven base table in execution order; preparation alone grants no such right. */
final readonly class TableDrop
{
    /**
     * @param int $index Configured schema index
     * @param string $table Exact unqualified base table name
     */
    public function __construct(public int $index, public string $table)
    {
    }
}
