<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** One table's lineage at a particular position of the UP replay. */
final readonly class ProjectedObject
{
    /**
     * @param TableName $name Exact physical table
     * @param ?TableStructure $structure Known structure, or an opaque archived/retained object
     * @param ?MigrationStep $origin Post-archive direct CREATE that introduced this lineage
     * @param bool $retained Whether these target-only rows must survive the restore
     * @param bool $baseTable Whether the object is a base table rather than a view
     */
    public function __construct(
        public TableName $name,
        public ?TableStructure $structure,
        public ?MigrationStep $origin,
        public bool $retained = false,
        public bool $baseTable = true,
    ) {
    }
}
