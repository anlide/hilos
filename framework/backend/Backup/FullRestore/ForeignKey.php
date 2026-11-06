<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class ForeignKey
{
    /**
     * @param ?string $name Explicit constraint name, when declared
     * @param list<string> $columns Referencing columns in declared order
     * @param ?string $referencedDatabase Explicit database qualifier, when declared
     * @param string $referencedTable Referenced table, without a database qualifier
     * @param list<string> $referencedColumns Referenced columns in declared order
     */
    public function __construct(
        public ?string $name,
        public array $columns,
        public ?string $referencedDatabase,
        public string $referencedTable,
        public array $referencedColumns,
    ) {
    }
}
