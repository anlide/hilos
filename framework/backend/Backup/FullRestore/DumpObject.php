<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class DumpObject
{
    /**
     * @param string $name Unqualified object name
     * @param DumpObjectType $type Final object kind, after mysqldump's view placeholders
     * @param string $createSql Original CREATE statement, including executable comment wrappers
     * @param list<string> $columns Table columns in storage order; empty for views
     * @param list<ForeignKey> $foreignKeys Declared table dependencies, without sorting or resolution
     */
    public function __construct(
        public string $name,
        public DumpObjectType $type,
        public string $createSql,
        public array $columns,
        public array $foreignKeys,
    ) {
    }
}
